<?php
ob_start();
session_start();
include 'include/session.php';
include 'include/param.php';
include 'include/dbo.php';
include 'include/item.php';
include 'include/stock_journal.php';

$debug = 0;
/* File Name : dc-update.php

Update Delivery Challan (DC)
- Based on dc-add.php UI
- Update logic follows saleBS-update.php style using per-line rec_status (new/upd/del)

Key behavior:
- Existing rows can be soft-deleted in UI (rec_status=del) and are deleted in DB.
- Existing rows are updated in DB (rec_status=upd).
- New rows are inserted (rec_status=new).
- Row identification for update/delete uses (parent_dc_id, item_srl_no) which matches dc-add insert logic.

*/

checksession();
$dtm = getLocalDtm();
$ip_address = $_SERVER['REMOTE_ADDR'];
$login_user = $_SESSION['pos_login'];
$biz_id = $_SESSION['biz_id'];
include 'company-info.php';

$dbh = new dbo();
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$item_obj = new Item();
$stk_j = new Stock_Journal($dbh);

$doc_type = 'DELIVERY CHALLAN';
include 'config-doc-entry-info.php'; // outputs: $allow_remark_txn ; $allow_remark_item

// -----------------------------------------------------------------------------
// Inputs
// -----------------------------------------------------------------------------
// dc-manage.php opens this page via POST with fields:
//   src_loc=dc-manage
//   dc_id=<id>
// Keep it compatible with both GET and POST callers.

	$src_loc = trim((string)($_REQUEST['src_loc'] ?? 'dc-manage.php'));
	$dc_id = (int)($_REQUEST['update_id'] ?? $_REQUEST['dc_id'] ?? 0);

	$err_msg = '';
	$ok_msg  = '';

	if ($dc_id <= 0) {
		$err_msg = 'Missing DC id (dc_id).';
	}


if (isset($_POST['dc_update'])){              // From dc-manage
	// -----------------------------------------------------------------------------
	// Load existing DC header/details for display
	// -----------------------------------------------------------------------------
	$dc_hdr = null;
	$dc_det = [];
	$ewb_valid_upto_disp = '';

	if ($dc_id > 0) {
		try {
			$stmtH = $dbh->prepare('SELECT * FROM table_dc_header WHERE biz_id=:biz_id AND dc_id=:dc_id LIMIT 1');
			$stmtH->execute([':biz_id'=>$biz_id, ':dc_id'=>$dc_id]);
			$dc_hdr = $stmtH->fetch(PDO::FETCH_ASSOC);

			if (!$dc_hdr) {
				$err_msg = 'Delivery Challan not found.';
			} 
			else 
			{	
				// Compute initial dispatch mode from data (because dc-add doesn't store dispatch_mode)
				$dispatch_mode_init = 'NONE';
				if ($dc_hdr) {
					$dispatch_mode_init = $dc_hdr['dispatch_mode'];
				}

				
				$stmtD = $dbh->prepare('SELECT * FROM table_dc_details WHERE parent_dc_id=:dc_id ORDER BY item_srl_no');
				$stmtD->execute([':dc_id'=>$dc_id]);
				$dc_det = $stmtD->fetchAll(PDO::FETCH_ASSOC);

				// Load EWB valid upto (stored in table_ewb)
				$stmtE = $dbh->prepare('SELECT ewb_valid_upto FROM table_ewb WHERE biz_id=:biz_id AND txn_type=\'DC\' AND txn_id=:txn_id LIMIT 1');
				$stmtE->execute([':biz_id'=>$biz_id, ':txn_id'=>$dc_id]);
				$ewb_row = $stmtE->fetch(PDO::FETCH_ASSOC);
				if ($ewb_row && !empty($ewb_row['ewb_valid_upto'])) {
					$ewb_valid_upto_disp = (string)$ewb_row['ewb_valid_upto'];
				}
			}

		} catch (Throwable $e) {
			$err_msg = $e->getMessage();
		}
	}

}

// -----------------------------------------------------------------------------
// Update handler
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit']) && $dc_id > 0) {
    try {
        // -----------------------------
        // 1) Read inputs (sanitize/trim)
        // -----------------------------
        $txn_type   = 'DC';

        $dc_num     = trim((string)($_POST['voucher_num'] ?? ''));
        $dc_dt      = trim((string)($_POST['voucher_date'] ?? ''));

        $party_id       = trim((string)($_POST['party_id'] ?? ''));
        $party_name     = trim((string)($_POST['party_name'] ?? ''));
        $party_address  = trim((string)($_POST['party_address'] ?? ''));
        $party_state    = trim((string)($_POST['party_state'] ?? ''));
        $party_pincode  = trim((string)($_POST['party_pincode'] ?? ''));
        $party_phone    = trim((string)($_POST['party_phone'] ?? ''));
        $party_gstin    = trim((string)($_POST['party_gstin'] ?? ''));

        $ref_order_num  = trim((string)($_POST['sup_doc_num'] ?? ''));
        $ref_order_dt   = trim((string)($_POST['sup_doc_date'] ?? ''));
        if ($ref_order_num === '') $ref_order_dt = null;

        $note = trim((string)($_POST['note'] ?? ''));

        // Shipping
        $diff_ship = isset($_POST['diff_ship']) ? 'Y' : 'N';
        $shp_party_name = $diff_ship === 'Y' ? trim((string)($_POST['party2_name'] ?? '')) : '';
        $shp_address    = $diff_ship === 'Y' ? trim((string)($_POST['party2_address'] ?? '')) : '';
        $shp_state      = $diff_ship === 'Y' ? trim((string)($_POST['party2_state'] ?? '')) : '';
        $shp_pincode    = $diff_ship === 'Y' ? trim((string)($_POST['party2_pincode'] ?? '')) : '';
        $shp_phone      = $diff_ship === 'Y' ? trim((string)($_POST['party2_phone'] ?? '')) : '';
        $shp_gstin      = $diff_ship === 'Y' ? trim((string)($_POST['party2_gstin'] ?? '')) : '';

        // Dispatch mode + dispatch fields
        $dispatch_mode   = strtoupper(trim((string)($_POST['dispatch_mode'] ?? 'NONE'))); // NONE/DISPATCH/EWB/BOTH

        $transport_mode  = trim((string)($_POST['transport_mode'] ?? ''));
        $vehicle_no      = trim((string)($_POST['vehicle_no'] ?? ''));
        $transport_doc_no= trim((string)($_POST['transport_doc_no'] ?? ''));
        $transport_doc_dt= trim((string)($_POST['transport_doc_dt'] ?? ''));
        $transporter_id  = trim((string)($_POST['transporter_id'] ?? ''));
        $transporter_name= trim((string)($_POST['transporter_name'] ?? ''));
        $distance_km     = ($_POST['distance_km'] ?? '') !== '' ? (int)$_POST['distance_km'] : null;
        $place_of_supply = trim((string)($_POST['place_of_supply'] ?? ''));

        // EWB fields
        $ewb_num        = preg_replace('/\s+/', '', (string)($_POST['ewb_num'] ?? ''));
        $ewb_dt         = trim((string)($_POST['ewb_dt'] ?? ''));
        $ewb_valid_upto = trim((string)($_POST['ewb_valid_upto'] ?? ''));

        // -----------------------------
        // 2) Decide GST txn type (local / interstate)
        // -----------------------------
        $gst_txn_type = 'local';
        if ($party_state !== '' && isset($comp_state) && $comp_state !== '') {
            $gst_txn_type = (strcasecmp($comp_state, $party_state) === 0) ? 'local' : 'interstate';
        }

        // -----------------------------
        // 3) Decide if we should upsert EWB
        // -----------------------------
        $should_upsert_ewb = ($ewb_num !== '') && ($dispatch_mode === 'EWB' || $dispatch_mode === 'BOTH');

        // -----------------------------
        // 4) Validate basics
        // -----------------------------
        if ($dc_num === '' || $dc_dt === '' || $party_state === '') {
            throw new RuntimeException('DC No, DC Date, and Party State are required.');
        }

        if (empty($_POST['item_id']) || !is_array($_POST['item_id'])) {
            throw new RuntimeException('At least one line item is required.');
        }

        // At least 1 non-deleted line
        $any_alive = false;
        $rec_status_arr = $_POST['rec_status'] ?? [];
        $n = count($_POST['item_id']);
        for ($i = 0; $i < $n; $i++) {
            $rs = strtoupper(trim((string)($rec_status_arr[$i] ?? '')));
            if ($rs !== 'del') { $any_alive = true; break; }
        }
        if (!$any_alive) {
            throw new RuntimeException('All line items are marked for deletion. Please keep at least one line item.');
        }

        // Unique DC No within biz
        $dupStmt = $dbh->prepare('SELECT dc_id FROM table_dc_header WHERE biz_id=:biz_id AND dc_num=:dc_num AND dc_id<>:dc_id LIMIT 1');
        $dupStmt->execute([':biz_id'=>$biz_id, ':dc_num'=>$dc_num, ':dc_id'=>$dc_id]);
        if ($dupStmt->rowCount() > 0) {
            throw new RuntimeException('Duplicate Delivery Challan No. Please use a unique number.');
        }

        // -----------------------------
        // 5) Prepared statements
        // -----------------------------
        $sqlUpdateHeader = "
            UPDATE table_dc_header
            SET
                txn_type = :txn_type,
                dc_num = :dc_num,
                dc_dt = :dc_dt,
                ref_order_num = :ref_order_num,
                ref_order_dt = :ref_order_dt,

                bil_party_id = :bil_party_id,
                bil_party_name = :bil_party_name,
                bil_address = :bil_address,
                bil_state = :bil_state,
                bil_pincode = :bil_pincode,
                bil_phone = :bil_phone,
                bil_gstin = :bil_gstin,

                diff_shp_add = :diff_shp_add,
                shp_party_name = :shp_party_name,
                shp_address = :shp_address,
                shp_state = :shp_state,
                shp_pincode = :shp_pincode,
                shp_phone = :shp_phone,
                shp_gstin = :shp_gstin,

                dispatch_mode = :dispatch_mode,
                transport_mode = :transport_mode,
                vehicle_no = :vehicle_no,
                transport_doc_no = :transport_doc_no,
                transport_doc_dt = :transport_doc_dt,
                transporter_id = :transporter_id,
                transporter_name = :transporter_name,
                distance_km = :distance_km,
                place_of_supply = :place_of_supply,

                note = :note
            WHERE dc_id = :dc_id AND biz_id = :biz_id
        ";

        // Detail update/delete by (parent_dc_id, item_srl_no)
        $sqlUpdateDetail = "
            UPDATE table_dc_details
            SET
                ref_order_details_id = :ref_order_details_id,
                item_id = :item_id,
                item_name = :item_name,
                item_note = :item_note,
                uom = :uom,
                qty = :qty,
                price = :price,
                discount_mode = :discount_mode,
                discount_amt = :discount_amt,
                discount_pct = :discount_pct,
                hsn_code = :hsn_code,
                gst_pct = :gst_pct,
                taxable_amt = :taxable_amt,
                cgst_amt = :cgst_amt,
                sgst_amt = :sgst_amt,
                igst_amt = :igst_amt,
                gst_amt = :gst_amt,
                line_total = :line_total
            WHERE parent_dc_id = :parent_dc_id AND item_srl_no = :item_srl_no
        ";

        $sqlDeleteDetail = 'DELETE FROM table_dc_details WHERE parent_dc_id = :parent_dc_id AND item_srl_no = :item_srl_no';

        $sqlInsertDetail = "
            INSERT INTO table_dc_details (
                parent_dc_id, ref_order_details_id, item_srl_no,
                item_id, item_name, item_note, uom, qty, price,
                discount_mode, discount_amt, discount_pct,
                hsn_code, gst_pct,
                taxable_amt, cgst_amt, sgst_amt, igst_amt, gst_amt, line_total
            )
            VALUES (
                :parent_dc_id, :ref_order_details_id, :item_srl_no,
                :item_id, :item_name, :item_note, :uom, :qty, :price,
                :discount_mode, :discount_amt, :discount_pct,
                :hsn_code, :gst_pct,
                :taxable_amt, :cgst_amt, :sgst_amt, :igst_amt, :gst_amt, :line_total
            )
        ";

        $sqlUpdateHeaderTotals = "
            UPDATE table_dc_header
            SET
                gst_txn_type = :gst_txn_type,
                total_amt  = :total_amt,
                cgst_amt   = :cgst_amt,
                sgst_amt   = :sgst_amt,
                igst_amt   = :igst_amt,
                total_tax  = :total_tax,
                net_amt    = :net_amt
            WHERE dc_id = :dc_id AND biz_id = :biz_id
        ";

        $sqlFindEwb = 'SELECT ewb_id FROM table_ewb WHERE biz_id=:biz_id AND txn_type=\'DC\' AND txn_id=:txn_id LIMIT 1';
        $sqlInsertEwb = "
            INSERT INTO table_ewb (
                biz_id, txn_type, txn_id,
                ewb_num, ewb_dt, ewb_valid_upto,
                transport_mode, vehicle_no,
                transporter_id, transporter_name,
                transport_doc_no, transport_doc_dt,
                distance_km, place_of_supply,
                created_by, created_dtm, created_ip
            )
            VALUES (
                :biz_id, :txn_type, :txn_id,
                :ewb_num, :ewb_dt, :ewb_valid_upto,
                :transport_mode, :vehicle_no,
                :transporter_id, :transporter_name,
                :transport_doc_no, :transport_doc_dt,
                :distance_km, :place_of_supply,
                :created_by, :created_dtm, :created_ip
            )
        ";

        $sqlUpdateEwb = "
            UPDATE table_ewb
            SET
                ewb_num = :ewb_num,
                ewb_dt = :ewb_dt,
                ewb_valid_upto = :ewb_valid_upto,
                transport_mode = :transport_mode,
                vehicle_no = :vehicle_no,
                transporter_id = :transporter_id,
                transporter_name = :transporter_name,
                transport_doc_no = :transport_doc_no,
                transport_doc_dt = :transport_doc_dt,
                distance_km = :distance_km,
                place_of_supply = :place_of_supply
            WHERE ewb_id = :ewb_id AND biz_id = :biz_id
        ";

        $sqlDeleteEwb = 'DELETE FROM table_ewb WHERE biz_id=:biz_id AND txn_type=\'DC\' AND txn_id=:txn_id';

        $sqlLinkHeaderEwb = "
            UPDATE table_dc_header
            SET ewb_id = :ewb_id, ewb_num = :ewb_num, ewb_dt = :ewb_dt
            WHERE dc_id = :dc_id AND biz_id = :biz_id
        ";

        $sqlClearHeaderEwb = "
            UPDATE table_dc_header
            SET ewb_id = NULL, ewb_num = NULL, ewb_dt = NULL
            WHERE dc_id = :dc_id AND biz_id = :biz_id
        ";

        $stmtHdr   = $dbh->prepare($sqlUpdateHeader);
        $stmtUpdD  = $dbh->prepare($sqlUpdateDetail);
        $stmtDelD  = $dbh->prepare($sqlDeleteDetail);
        $stmtInsD  = $dbh->prepare($sqlInsertDetail);
        $stmtTot   = $dbh->prepare($sqlUpdateHeaderTotals);
        $stmtFindE = $dbh->prepare($sqlFindEwb);
        $stmtInsE  = $dbh->prepare($sqlInsertEwb);
        $stmtUpdE  = $dbh->prepare($sqlUpdateEwb);
        $stmtDelE  = $dbh->prepare($sqlDeleteEwb);
        $stmtLinkE = $dbh->prepare($sqlLinkHeaderEwb);
        $stmtClrE  = $dbh->prepare($sqlClearHeaderEwb);

        // -----------------------------
        // 6) Execute
        // -----------------------------
        if ($dbh->inTransaction() === false) $dbh->beginTransaction();

        // 6.1 Header update
        $stmtHdr->execute([
            ':txn_type'      => $txn_type,
            ':dc_num'        => $dc_num,
            ':dc_dt'         => $dc_dt,
            ':ref_order_num' => ($ref_order_num !== '' ? $ref_order_num : null),
            ':ref_order_dt'  => ($ref_order_dt !== '' ? $ref_order_dt : null),

            ':bil_party_id'   => ($party_id !== '' ? $party_id : null),
            ':bil_party_name' => ($party_name !== '' ? $party_name : null),
            ':bil_address'    => ($party_address !== '' ? $party_address : null),
            ':bil_state'      => $party_state,
            ':bil_pincode'    => ($party_pincode !== '' ? $party_pincode : null),
            ':bil_phone'      => ($party_phone !== '' ? $party_phone : null),
            ':bil_gstin'      => ($party_gstin !== '' ? $party_gstin : null),

            ':diff_shp_add'   => $diff_ship,
            ':shp_party_name' => ($shp_party_name !== '' ? $shp_party_name : null),
            ':shp_address'    => ($shp_address !== '' ? $shp_address : null),
            ':shp_state'      => ($shp_state !== '' ? $shp_state : null),
            ':shp_pincode'    => ($shp_pincode !== '' ? $shp_pincode : null),
            ':shp_phone'      => ($shp_phone !== '' ? $shp_phone : null),
            ':shp_gstin'      => ($shp_gstin !== '' ? $shp_gstin : null),

            ':dispatch_mode'  => ($dispatch_mode !== '' ? $dispatch_mode : 'NONE'),
            ':transport_mode'  => ($transport_mode !== '' ? $transport_mode : null),
            ':vehicle_no'      => ($vehicle_no !== '' ? $vehicle_no : null),
            ':transport_doc_no'=> ($transport_doc_no !== '' ? $transport_doc_no : null),
            ':transport_doc_dt'=> ($transport_doc_dt !== '' ? $transport_doc_dt : null),
            ':transporter_id'  => ($transporter_id !== '' ? $transporter_id : null),
            ':transporter_name'=> ($transporter_name !== '' ? $transporter_name : null),
            ':distance_km'     => $distance_km,
            ':place_of_supply' => ($place_of_supply !== '' ? $place_of_supply : null),

            ':note'           => ($note !== '' ? $note : null),

            ':dc_id'          => $dc_id,
            ':biz_id'         => $biz_id,
        ]);

        // 6.2 Compute current max item_srl_no in DB
        $maxSrlStmt = $dbh->prepare('SELECT COALESCE(MAX(item_srl_no), 0) AS mx FROM table_dc_details WHERE parent_dc_id=:dc_id');
        $maxSrlStmt->execute([':dc_id' => $dc_id]);
        $mxRow = $maxSrlStmt->fetch(PDO::FETCH_ASSOC);
        $max_srl = (int)($mxRow['mx'] ?? 0);

        // 6.3 Detail upsert/delete + totals
        $total_taxable = 0.00;
        $total_cgst    = 0.00;
        $total_sgst    = 0.00;
        $total_igst    = 0.00;
        $total_gst     = 0.00;
        $total_net     = 0.00;

        $item_srl_arr = $_POST['item_srl_no'] ?? [];
        $ref_od_arr   = $_POST['ref_order_details_id'] ?? [];
        $disc_mode_arr= $_POST['discount_mode'] ?? [];
        $disc_amt_arr = $_POST['discount_amt'] ?? [];
        $disc_pct_arr = $_POST['discount_pct'] ?? [];
        $item_note_arr= $_POST['item_note'] ?? [];

        for ($i = 0; $i < $n; $i++) {                 // For each item record based on $rec_status - upd, del, new
            $rs = strtoupper(trim((string)($rec_status_arr[$i] ?? '')));
            if ($rs === '') $rs = 'upd';

            $orig_srl = (int)($item_srl_arr[$i] ?? 0);

            // Delete
            if ($rs === 'del') {
                if ($orig_srl > 0) {
                    $stmtDelD->execute([':parent_dc_id'=>$dc_id, ':item_srl_no'=>$orig_srl]);
                }
                continue;
            }
			
            // Read values
            $item_id    = ($_POST['item_id'][$i] ?? '') !== '' ? (int)$_POST['item_id'][$i] : null;
            $item_name  = trim((string)($_POST['item_name'][$i] ?? ''));
            $uom        = trim((string)($_POST['uom'][$i] ?? ''));
            $hsn        = trim((string)($_POST['hsn_sac'][$i] ?? ''));
            $qty        = (float)($_POST['quantity'][$i] ?? 0);
            $price      = (float)($_POST['item_price'][$i] ?? 0);
            $gst_pct    = (float)($_POST['itemGST'][$i] ?? 0);

            $item_note  = trim((string)($item_note_arr[$i] ?? ''));
            $discount_mode = strtoupper(trim((string)($disc_mode_arr[$i] ?? '')));
            $discount_amt  = (float)($disc_amt_arr[$i] ?? 0);
            $discount_pct  = ($disc_pct_arr[$i] ?? '') !== '' ? (float)$disc_pct_arr[$i] : null;
            $ref_order_detid = ($ref_od_arr[$i] ?? '') !== '' ? (int)$ref_od_arr[$i] : null;

            // Assign new srl if needed
            $cur_srl = $orig_srl;
            if ($rs === 'new' || $cur_srl <= 0) {
                $cur_srl = ++$max_srl;
            }

            // Compute
            $base = $qty * $price;
            $disc = 0.00;
            if ($discount_mode === 'PCT' && $discount_pct !== null) {
                $disc = $base * ($discount_pct / 100.0);
            } elseif ($discount_mode === 'AMT') {
                $disc = $discount_amt;
            } else {
                if ($discount_amt > 0) $disc = $discount_amt;
            }
            if ($disc < 0) $disc = 0.00;
            if ($disc > $base) $disc = $base;

            $taxable = $base - $disc;
            $cgst = 0.00; $sgst = 0.00; $igst = 0.00;
            if ($gst_txn_type === 'local') {
                $cgst = $taxable * ($gst_pct / 200.0);
                $sgst = $taxable * ($gst_pct / 200.0);
            } else {
                $igst = $taxable * ($gst_pct / 100.0);
            }
            $gst_amt = $cgst + $sgst + $igst;
            $line_total = $taxable + $gst_amt;

            $taxable    = round($taxable, 2);
            $cgst       = round($cgst, 2);
            $sgst       = round($sgst, 2);
            $igst       = round($igst, 2);
            $gst_amt    = round($gst_amt, 2);
            $line_total = round($line_total, 2);

            $total_taxable += $taxable;
            $total_cgst    += $cgst;
            $total_sgst    += $sgst;
            $total_igst    += $igst;
            $total_gst     += $gst_amt;
            $total_net     += $line_total;

            $payload = [
                ':parent_dc_id' => $dc_id,
                ':item_srl_no' => $cur_srl,
                ':ref_order_details_id' => $ref_order_detid,
                ':item_id' => $item_id,
                ':item_name' => ($item_name !== '' ? $item_name : null),
                ':item_note' => ($item_note !== '' ? $item_note : null),
                ':uom' => ($uom !== '' ? $uom : null),
                ':qty' => $qty,
                ':price' => $price,
                ':discount_mode' => ($discount_mode !== '' ? $discount_mode : null),
                ':discount_amt' => $discount_amt,
                ':discount_pct' => $discount_pct,
                ':hsn_code' => ($hsn !== '' ? $hsn : null),
                ':gst_pct' => $gst_pct,
                ':taxable_amt' => $taxable,
                ':cgst_amt' => $cgst,
                ':sgst_amt' => $sgst,
                ':igst_amt' => $igst,
                ':gst_amt' => $gst_amt,
                ':line_total' => $line_total,
            ];

            if ($rs === 'upd' && $orig_srl > 0) {
                // If srl changed (shouldn't), keep where using orig_srl.
                $payloadUpd = $payload;
                unset($payloadUpd[':item_srl_no']);
                $payloadUpd[':parent_dc_id'] = $dc_id;
                $payloadUpd[':item_srl_no'] = $orig_srl;
                $stmtUpdD->execute($payloadUpd);

                // If a new row got assigned new srl but had orig_srl, we don't support re-numbering.
            } else {
                // Insert with assigned srl
                $stmtInsD->execute($payload);
            }
        }

        // 6.4 Header totals update
        $total_taxable = round($total_taxable, 2);
        $total_cgst    = round($total_cgst, 2);
        $total_sgst    = round($total_sgst, 2);
        $total_igst    = round($total_igst, 2);
        $total_gst     = round($total_gst, 2);
        $net_amt = round($total_taxable + $total_gst, 2);

        $stmtTot->execute([
            ':gst_txn_type' => $gst_txn_type,
            ':total_amt' => $total_taxable,
            ':cgst_amt'  => $total_cgst,
            ':sgst_amt'  => $total_sgst,
            ':igst_amt'  => $total_igst,
            ':total_tax' => $total_gst,
            ':net_amt'   => $net_amt,
            ':dc_id'     => $dc_id,
            ':biz_id'    => $biz_id,
        ]);

        // 6.5 EWB upsert/delete + link/clear header mirror
        if ($should_upsert_ewb) {
            if ($ewb_dt === '') {
                throw new RuntimeException('EWB Date is required when EWB No is entered.');
            }

            $stmtFindE->execute([':biz_id'=>$biz_id, ':txn_id'=>$dc_id]);
            $ewb_row = $stmtFindE->fetch(PDO::FETCH_ASSOC);
            $ewb_id = (int)($ewb_row['ewb_id'] ?? 0);

            if ($ewb_id > 0) {
                $stmtUpdE->execute([
                    ':ewb_id' => $ewb_id,
                    ':biz_id' => $biz_id,
                    ':ewb_num' => $ewb_num,
                    ':ewb_dt' => $ewb_dt,
                    ':ewb_valid_upto' => ($ewb_valid_upto !== '' ? $ewb_valid_upto : null),
                    ':transport_mode' => ($transport_mode !== '' ? $transport_mode : null),
                    ':vehicle_no' => ($vehicle_no !== '' ? $vehicle_no : null),
                    ':transporter_id' => ($transporter_id !== '' ? $transporter_id : null),
                    ':transporter_name' => ($transporter_name !== '' ? $transporter_name : null),
                    ':transport_doc_no' => ($transport_doc_no !== '' ? $transport_doc_no : null),
                    ':transport_doc_dt' => ($transport_doc_dt !== '' ? $transport_doc_dt : null),
                    ':distance_km' => $distance_km,
                    ':place_of_supply' => ($place_of_supply !== '' ? $place_of_supply : null),
                ]);
            } else {
                $stmtInsE->execute([
                    ':biz_id' => $biz_id,
                    ':txn_type' => 'DC',
                    ':txn_id' => $dc_id,
                    ':ewb_num' => $ewb_num,
                    ':ewb_dt' => $ewb_dt,
                    ':ewb_valid_upto' => ($ewb_valid_upto !== '' ? $ewb_valid_upto : null),
                    ':transport_mode' => ($transport_mode !== '' ? $transport_mode : null),
                    ':vehicle_no' => ($vehicle_no !== '' ? $vehicle_no : null),
                    ':transporter_id' => ($transporter_id !== '' ? $transporter_id : null),
                    ':transporter_name' => ($transporter_name !== '' ? $transporter_name : null),
                    ':transport_doc_no' => ($transport_doc_no !== '' ? $transport_doc_no : null),
                    ':transport_doc_dt' => ($transport_doc_dt !== '' ? $transport_doc_dt : null),
                    ':distance_km' => $distance_km,
                    ':place_of_supply' => ($place_of_supply !== '' ? $place_of_supply : null),
                    ':created_by' => $login_user,
                    ':created_dtm' => $dtm,
                    ':created_ip' => $ip_address,
                ]);
                $ewb_id = (int)$dbh->lastInsertId();
            }

            $stmtLinkE->execute([
                ':ewb_id' => $ewb_id,
                ':ewb_num' => $ewb_num,
                ':ewb_dt' => $ewb_dt,
                ':dc_id' => $dc_id,
                ':biz_id' => $biz_id,
            ]);

        } else {
            // If EWB should not exist, remove any existing EWB row and clear header mirror
            $stmtDelE->execute([':biz_id'=>$biz_id, ':txn_id'=>$dc_id]);
            $stmtClrE->execute([':dc_id'=>$dc_id, ':biz_id'=>$biz_id]);
        }

        if ($dbh->inTransaction()) $dbh->commit();

        $ok_msg = 'Delivery Challan updated: ' . $dc_num;

        echo "<script>\n";
        echo "alert(" . json_encode($ok_msg) . ");\n";
        echo "window.location.href = " . json_encode($src_loc) . ";\n";
        echo "</script>";
        exit;

    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        $err_msg = $e->getMessage();
    }
}



// Convenience values
$val = function($k, $default='') use ($dc_hdr) {
    if (!$dc_hdr) return $default;
    return isset($dc_hdr[$k]) && $dc_hdr[$k] !== null ? (string)$dc_hdr[$k] : $default;
};

$diff_ship_checked = ($val('diff_shp_add','N') === 'Y');

?>
<!doctype html>
<html lang="en">
<head>
  <title>Delivery Challan Update</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=yes">

  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" type="text/css" rel="stylesheet">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

  <style>
    .fld12 { width: 12ch; max-width: 12ch; }
    .row-del { background: #ffe6e6; opacity: 0.75; }
    .row-del input, .row-del textarea, .row-del select { pointer-events:none; }
  </style>

  <script type="text/javascript">
    function searchName(){
      var biz_id = $('#biz_id').val();
      var cust_name = $('#srch_cust_name').val();
      $.post('party-search-name-ajax.php',
        {p_act_grp:'customer', p_biz_id:biz_id, p_cust_name:cust_name},
        function(html){ $('#searchOutput').html(html).show(); }
      );
    }

    function searchPhone(){
      var biz_id = $('#biz_id').val();
      var phone  = $('#srch_cust_number').val();
      $.post('party-search-contact-ajax.php',
        {p_act_grp:'customer', p_biz_id:biz_id, p_cust_number:phone},
        function(html){ $('#searchOutput').html(html).show(); }
      );
    }

    function searchEmail(){
      var biz_id = $('#biz_id').val();
      var email  = $('#srch_cust_email').val();
      $.post('party-search-email-ajax.php',
        {p_act_grp:'customer', p_biz_id:biz_id, p_cust_email:email},
        function(html){ $('#searchOutput').html(html).show(); }
      );
    }

    function set_party(val){
      var parts = (val||'').split(':');
      $.post('party-info-fetch-ajax.php', {cust_id: parts[0]}, function(resp){
        var obj = JSON.parse(resp||'{}');
        $('#party_id').val(obj.account_id||'');
        $('#party_name').val(obj.account_name||'');
        document.getElementById('party_name_dup').value = obj.account_name || '';
        $('#party_address').val(obj.address||'');
        $('#party_phone').val(obj.phone_num||'');
        $('#party_state').val(obj.state||'');
        $('#party_pincode').val(obj.pincode||'');
        $('#party_gstin').val(obj.gstin||'');
        $('#sup_doc_num').focus();
      });
    }

    function toggleParty(cb_party_det){
      var x = document.getElementById('PartyDetails');
      x.style.display = (cb_party_det.checked ? 'block' : 'none');
    }

    function diffShipping(ck_box){
      var x = document.getElementById('ShipTo');
      if (ck_box.checked) {
        x.style.display = 'block';
        $('#btnCopyBillToShip').show();
      } else {
        x.style.display = 'none';
        $('#btnCopyBillToShip').hide();
        $('#party2_name').val('');
        $('#party2_address').val('');
        $('#party2_state').val('');
        $('#party2_pincode').val('');
        $('#party2_phone').val('');
        $('#party2_gstin').val('');
      }
    }

    function copyBillToShip(){
      $('#party2_name').val($('#party_name').val() || '');
      $('#party2_address').val($('#party_address').val() || '');
      $('#party2_state').val($('#party_state').val() || '');
      $('#party2_pincode').val($('#party_pincode').val() || '');
      $('#party2_phone').val($('#party_phone').val() || '');
      $('#party2_gstin').val($('#party_gstin').val() || '');
    }

    function set_voucher_numbering_mode(){ 
		document.getElementById('manual').checked = true; 
		}
  </script>
</head>

<body style="background-color:#ccf2ff;">
  <div>
    <?php include 'header.inc.php'; ?>
  </div>

  <main>
    <div class="container container-md mt-10 p-4">
      <center><h3 class="text-primary" style="margin-top:50px;">Delivery Challan Update</h3></center>

      <?php if ($err_msg !== ''): ?>
        <div class="alert alert-danger" style="margin-top:15px;"><?php echo htmlspecialchars($err_msg); ?></div>
      <?php endif; ?>
      <?php if ($ok_msg !== ''): ?>
        <div class="alert alert-success" style="margin-top:15px;"><?php echo htmlspecialchars($ok_msg); ?></div>
      <?php endif; ?>

      <form method="POST" id="dc_update_form">
        <input hidden id="biz_id" name="biz_id" value="<?php echo htmlspecialchars((string)$biz_id); ?>" type="text">
        <input type="hidden" name="update_id" value="<?php echo htmlspecialchars((string)$dc_id); ?>">
        <input type="hidden" name="src_loc" value="<?php echo htmlspecialchars((string)$src_loc); ?>">

        <br>

        <div class="form-group row">
          <label class="control-label col-md-2" for="voucher_num">Delivery Challan No<span style="color:red">*</span></label>
          <div class="col-md-3">
            <input name="voucher_num" id="voucher_num" required class="input-md" type="text"
                   value="<?php echo htmlspecialchars($val('dc_num','')); ?>" onchange="set_voucher_numbering_mode()">
            <br>
            <input type="checkbox" name="manual" id="manual">
            <label for="manual">Manual Numbering</label>
          </div>

          <label class="control-label col-md-2" for="voucher_date">Delivery Challan Date<span style="color:red">*</span></label>
          <div class="col-md-3">
            <input name="voucher_date" id="voucher_date" required class="input-md" type="date"
                   value="<?php echo htmlspecialchars(substr($val('dc_dt',''),0,10)); ?>">
          </div>
        </div>

        <div class="form-group row">
          <label class="control-label col-md-2" for="BillTo"><b>Party Details:</b></label>
          <div class="col-md-2">
            <button type="button" class="btn bill_btn btn-info" data-toggle="modal" data-target="#PartyModal">Select Party</button>
          </div>
          <label class="control-label col-md-2" for="name">Party ID/Name</label>
          <div class="col-md-1">
            <input readonly name="party_id" id="party_id" class="input-md" type="text" value="<?php echo htmlspecialchars($val('bil_party_id','')); ?>">
          </div>
          <div class="col-md-2">
            <input readonly name="party_name" id="party_name" class="input-md" type="text" value="<?php echo htmlspecialchars($val('bil_party_name','')); ?>">
          </div>
          <div class="col-md-3">
            Show/Hide Party Details
            <input type="checkbox" checked name="cb_party_det" id="cb_party_det" class="input-md" onchange="toggleParty(this)">
          </div>
        </div>

        <div id="PartyDetails" style="display:block;">
          <div class="row" style="margin-bottom:2px;">
            <div id="BillTO" class="col-md-6">
              Bill To Party:
              <div class="col-md-12">
                <label class="control-label col-md-3" for="name">Party Name</label>
                <div class="col-md-6">
                  <input readonly name="party_name_dup" id="party_name_dup" class="form-control" type="text" value="<?php echo htmlspecialchars($val('bil_party_name','')); ?>">
                </div>
              </div>
              <div class="col-md-12">
                <label class="control-label col-md-3" for="party_address">Address</label>
                <div class="col-md-6">
                  <input readonly type="text" name="party_address" id="party_address" class="form-control" value="<?php echo htmlspecialchars($val('bil_address','')); ?>">
                </div>
              </div>
              <div class="col-md-12">
                <label class="control-label col-md-3" for="party_state">State</label>
                <div class="col-md-6">
                  <input readonly type="text" name="party_state" id="party_state" class="form-control" value="<?php echo htmlspecialchars($val('bil_state','')); ?>">
                </div>
              </div>
              <div class="col-md-12">
                <label class="control-label col-md-3" for="party_pincode">PinCode</label>
                <div class="col-md-6">
                  <input readonly type="text" name="party_pincode" id="party_pincode" class="form-control" value="<?php echo htmlspecialchars($val('bil_pincode','')); ?>">
                </div>
              </div>
              <div class="col-md-12">
                <label class="control-label col-md-3" for="party_gstin">GSTIN</label>
                <div class="col-md-6">
                  <input readonly type="text" name="party_gstin" id="party_gstin" class="form-control" value="<?php echo htmlspecialchars($val('bil_gstin','')); ?>">
                </div>
              </div>
              <div class="col-md-12">
                <label class="control-label col-md-3" for="party_phone">Phone</label>
                <div class="col-md-6">
                  <input readonly type="text" name="party_phone" id="party_phone" class="form-control" value="<?php echo htmlspecialchars($val('bil_phone','')); ?>">
                </div>
              </div>
            </div>

            <div class="col-md-6">
              Different Ship To Address:
              <input name="diff_ship" id="diff_ship" class="input-md" type="checkbox" onchange="diffShipping(this)" <?php echo $diff_ship_checked ? 'checked' : ''; ?>>
              <button type="button" class="btn btn-default btn-xs" id="btnCopyBillToShip" style="margin-left:8px; display:none;" onclick="copyBillToShip()">Copy from Bill-To</button>

              <div id="ShipTo" style="display:none;">
                <div class="col-md-12">
                  <label class="control-label col-md-4" for="name">Name</label>
                  <div class="col-md-2">
                    <input name="party2_name" id="party2_name" class="input-md" type="text" value="<?php echo htmlspecialchars($val('shp_party_name','')); ?>">
                  </div>
                </div>
                <div class="col-md-12">
                  <label class="control-label col-md-4" for="party2_address">Address</label>
                  <div class="col-md-6">
                    <input type="text" name="party2_address" id="party2_address" value="<?php echo htmlspecialchars($val('shp_address','')); ?>">
                  </div>
                </div>
                <div class="col-md-12">
                  <label class="control-label col-md-4" for="party2_address_state">State</label>
                  <div class="col-md-2">
                    <input name="party2_state" id="party2_state" class="input-md" type="text" value="<?php echo htmlspecialchars($val('shp_state','')); ?>">
                  </div>
                </div>
                <div class="col-md-12">
                  <label class="control-label col-md-4" for="party2_pincode">PinCode</label>
                  <div class="col-md-2">
                    <input name="party2_pincode" id="party2_pincode" class="input-md" type="text" value="<?php echo htmlspecialchars($val('shp_pincode','')); ?>">
                  </div>
                </div>
                <div class="col-md-12">
                  <label class="control-label col-md-4" for="party2_gstin">GSTIN</label>
                  <div class="col-md-3">
                    <input type="text" name="party2_gstin" id="party2_gstin" class="input-md" value="<?php echo htmlspecialchars($val('shp_gstin','')); ?>">
                  </div>
                </div>
                <div class="col-md-12">
                  <label class="control-label col-md-4" for="party2_phone">Phone</label>
                  <div class="col-md-3">
                    <input type="text" name="party2_phone" id="party2_phone" class="input-md" value="<?php echo htmlspecialchars($val('shp_phone','')); ?>">
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>

        <div class="row" style="margin-bottom:2px; margin-top:10px;">
          <label class="control-label col-md-2" for="sup_doc_num">Order Number</label>
          <div class="col-md-2">
            <input name="sup_doc_num" id="sup_doc_num" class="input-md" type="text" value="<?php echo htmlspecialchars($val('ref_order_num','')); ?>">
          </div>
          <div class="col-md-1"></div>
          <label class="control-label col-md-2" for="sup_doc_date">Order Date</label>
          <div class="col-md-3">
            <input type="date" name="sup_doc_date" id="sup_doc_date" class="input-md" value="<?php echo htmlspecialchars(substr($val('ref_order_dt',''),0,10)); ?>" disabled>
          </div>
        </div>

        <div class="row" style="margin-top:10px; <?php if ($allow_remark_txn=='N') echo 'display:none;'; ?>">
          <div class="col-md-12">
            <label>Remark / Note (DC level)</label>
            <input type="text" class="form-control" name="note" id="note" maxlength="128" placeholder="Optional remark" value="<?php echo htmlspecialchars($val('note','')); ?>">
          </div>
        </div>

        <div class="card" style="border:1px solid #ddd; border-radius:4px; margin-top:15px;">
          <div class="card-header" style="padding:10px 15px; border-bottom:1px solid #ddd; background:#f5f5f5; font-weight:bold;">
            Line Items
            <button type="button" class="btn btn-primary btn-xs pull-right" id="btnOpenItemModal" data-toggle="modal" data-target="#ItemModal">Add Item</button>
          </div>

          <div class="card-body" style="padding:0;">
            <div class="table-responsive">
              <table class="table table-hover" style="margin:0;">
                <thead>
                  <tr>
                    <th>Name</th><th>HSN/SAC</th><th>UoM</th><th>Price</th><th>Quantity</th>
                    <th>Sub Total</th><th>GST</th><th>Line Total</th><th>Actions</th>
                  </tr>
                </thead>
                <tbody id="js1">

                  <?php
                    $rowCounter = 0;
                    if (!empty($dc_det)) {
                      foreach ($dc_det as $r) {
                        $rowCounter++;
                        $t = $rowCounter;
                        $iid = $r['item_id'] ?? '';
                        $iname = $r['item_name'] ?? '';
                        $inote = $r['item_note'] ?? '';
                        $uom = $r['uom'] ?? '';
                        $hsn = $r['hsn_code'] ?? '';
                        $qty = isset($r['qty']) ? (float)$r['qty'] : 0.0;
                        $price = isset($r['price']) ? (float)$r['price'] : 0.0;
                        $gst = isset($r['gst_pct']) ? (float)$r['gst_pct'] : 0.0;

                        $disc_mode = $r['discount_mode'] ?? '';
                        $disc_amt  = isset($r['discount_amt']) ? (float)$r['discount_amt'] : 0.0;
                        $disc_pct  = $r['discount_pct'] ?? '';
                        $ref_odid  = $r['ref_order_details_id'] ?? '';

                        $sub = $qty * $price;
                        $tot = $sub + ($sub * $gst / 100.0);
                  ?>
                    <tr id="prodRow_<?php echo $t; ?>">
                      <td>
                        <input type="hidden" name="rec_status[]" id="rec_status_<?php echo $t; ?>" value="upd">
                        <input type="hidden" name="item_srl_no[]" id="item_srl_no_<?php echo $t; ?>" value="<?php echo htmlspecialchars((string)($r['item_srl_no'] ?? $t)); ?>">
                        <input type="hidden" name="ref_order_details_id[]" value="<?php echo htmlspecialchars((string)$ref_odid); ?>">
                        <input type="hidden" name="discount_mode[]" value="<?php echo htmlspecialchars((string)$disc_mode); ?>">
                        <input type="hidden" name="discount_amt[]" value="<?php echo htmlspecialchars((string)$disc_amt); ?>">
                        <input type="hidden" name="discount_pct[]" value="<?php echo htmlspecialchars((string)$disc_pct); ?>">

                        <input type="hidden" name="item_id[]" value="<?php echo htmlspecialchars((string)$iid); ?>">
                        <input type="text" class="input-md" readonly id="item_name_<?php echo $t; ?>" name="item_name[]" value="<?php echo htmlspecialchars((string)$iname); ?>">

                        <textarea id="item_note_<?php echo $t; ?>" class="form-control form-control-lg" name="item_note[]" <?php if ($allow_remark_item === 'N') echo 'style="display:none"'; ?>><?php echo htmlspecialchars((string)$inote); ?></textarea>
                      </td>

                      <td>
                        <input type="text" class="input-md fld12" readonly maxlength="12" size="12" id="hsn_sac_<?php echo $t; ?>" name="hsn_sac[]" value="<?php echo htmlspecialchars((string)$hsn); ?>">
                      </td>

                      <td>
                        <input type="text" class="input-md fld12" readonly maxlength="12" size="12" id="uom_<?php echo $t; ?>" name="uom[]" value="<?php echo htmlspecialchars((string)$uom); ?>">
                      </td>

                      <td>
                        <input type="number" step="0.01" min="0" class="input-md fld12" id="item_price_<?php echo $t; ?>" name="item_price[]" value="<?php echo htmlspecialchars((string)$price); ?>">
                      </td>

                      <td>
                        <input type="number" step="0.001" min="0" class="input-md fld12" id="quantity_<?php echo $t; ?>" name="quantity[]" value="<?php echo htmlspecialchars((string)$qty); ?>">
                      </td>

                      <td><span id="itemSubTotal_<?php echo $t; ?>"><?php echo number_format($sub, 2, '.', ''); ?></span></td>

                      <td>
                        <input type="number" step="0.01" min="0" class="input-md fld12" id="itemGST_<?php echo $t; ?>" name="itemGST[]" value="<?php echo htmlspecialchars((string)$gst); ?>">
                      </td>

                      <td><span id="itemTotal_<?php echo $t; ?>"><?php echo number_format($tot, 2, '.', ''); ?></span></td>

                      <td>
                        <button type="button" class="btn btn-danger btn-xs" onclick="toggleDeleteRow(<?php echo $t; ?>)">X</button>
                      </td>
                    </tr>

                  <?php
                      }
                    }
                  ?>

                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ================= Dispatch / E-Way Bill ================= -->
        <div class="card" style="border:1px solid #ddd; border-radius:4px; margin-top:15px;">
          <div class="card-header" style="padding:10px 15px; border-bottom:1px solid #ddd; background:#f5f5f5; font-weight:bold;">
            Dispatch / E-Way Bill Details
          </div>

          <div class="card-body" style="padding:15px;">
            <div class="row" style="margin-bottom:10px;">
              <div class="col-md-12">
                <label class="control-label"><b>What information do you want to enter?</b></label><br>
                <label class="radio-inline"><input type="radio" name="dispatch_mode" value="NONE" <?php echo ($dispatch_mode_init==='NONE'?'checked':''); ?>> None</label>
                <label class="radio-inline"><input type="radio" name="dispatch_mode" value="DISPATCH" <?php echo ($dispatch_mode_init==='DISPATCH'?'checked':''); ?>> Dispatch details</label>
                <label class="radio-inline"><input type="radio" name="dispatch_mode" value="EWB" <?php echo ($dispatch_mode_init==='EWB'?'checked':''); ?>> E-Way Bill</label>
                <label class="radio-inline"><input type="radio" name="dispatch_mode" value="BOTH" <?php echo ($dispatch_mode_init==='BOTH'?'checked':''); ?>> Both</label>
              </div>
            </div>

            <!-- Dispatch Panel -->
            <div class="panel panel-default" id="panelDispatch" style="display:none;">
              <div class="panel-heading" style="cursor:pointer;" data-toggle="collapse" data-target="#collapseDispatch">
                <h4 class="panel-title">Dispatch Details <span class="pull-right glyphicon glyphicon-chevron-down"></span></h4>
              </div>
              <div id="collapseDispatch" class="panel-collapse collapse in">
                <div class="panel-body">
                  <div class="row">
                    <div class="col-md-3">
                      <label>Transport Mode</label>
                      <select class="form-control" name="transport_mode" id="transport_mode">
                        <option value="">-- Select --</option>
                        <?php
                          $tm = strtoupper($val('transport_mode',''));
                          $opts = ['ROAD'=>'Road','RAIL'=>'Rail','AIR'=>'Air','SHIP'=>'Ship','COURIER'=>'Courier','HAND'=>'Hand Delivery'];
                          foreach ($opts as $k=>$lbl) {
                            $sel = ($tm === $k) ? 'selected' : '';
                            echo "<option value=\"$k\" $sel>$lbl</option>";
                          }
                        ?>
                      </select>
                    </div>
                    <div class="col-md-3">
                      <label>Vehicle No</label>
                      <input type="text" class="form-control" name="vehicle_no" id="vehicle_no" maxlength="20" value="<?php echo htmlspecialchars($val('vehicle_no','')); ?>" placeholder="e.g. DL01AB1234">
                    </div>
                    <div class="col-md-3">
                      <label>Transport Doc No (LR/AWB)</label>
                      <input type="text" class="form-control" name="transport_doc_no" id="transport_doc_no" maxlength="32" value="<?php echo htmlspecialchars($val('transport_doc_no','')); ?>" placeholder="LR/AWB No">
                    </div>
                    <div class="col-md-3">
                      <label>Transport Doc Date</label>
                      <input type="date" class="form-control" name="transport_doc_dt" id="transport_doc_dt" value="<?php echo htmlspecialchars(substr($val('transport_doc_dt',''),0,10)); ?>">
                    </div>
                  </div>

                  <div class="row" style="margin-top:10px;">
                    <div class="col-md-3">
                      <label>Transporter ID</label>
                      <input type="text" class="form-control" name="transporter_id" id="transporter_id" maxlength="32" value="<?php echo htmlspecialchars($val('transporter_id','')); ?>">
                    </div>
                    <div class="col-md-3">
                      <label>Transporter Name</label>
                      <input type="text" class="form-control" name="transporter_name" id="transporter_name" maxlength="64" value="<?php echo htmlspecialchars($val('transporter_name','')); ?>">
                    </div>
                    <div class="col-md-3">
                      <label>Distance (KM)</label>
                      <input type="number" class="form-control" name="distance_km" id="distance_km" min="0" step="1" value="<?php echo htmlspecialchars($val('distance_km','')); ?>">
                    </div>
                    <div class="col-md-3">
                      <label>Place of Supply</label>
                      <input type="text" class="form-control" name="place_of_supply" id="place_of_supply" maxlength="64" value="<?php echo htmlspecialchars($val('place_of_supply','')); ?>" placeholder="e.g. Delhi">
                    </div>
                  </div>

                  <p class="help-block" style="margin-top:10px;">Tip: For Road transport, Vehicle No is typically required. For Courier/Air, Transport Doc No is more relevant.</p>
                </div>
              </div>
            </div>

            <!-- EWB Panel -->
            <div class="panel panel-default" id="panelEwb" style="display:none;">
              <div class="panel-heading" style="cursor:pointer;" data-toggle="collapse" data-target="#collapseEwb">
                <h4 class="panel-title">E-Way Bill Details <span class="pull-right glyphicon glyphicon-chevron-down"></span></h4>
              </div>
              <div id="collapseEwb" class="panel-collapse collapse in">
                <div class="panel-body">
                  <div class="row">
                    <div class="col-md-3">
                      <label>EWB No</label>
                      <input type="text" class="form-control" name="ewb_num" id="ewb_num" maxlength="16" value="<?php echo htmlspecialchars($val('ewb_num','')); ?>" placeholder="12-digit EWB">
                    </div>
                    <div class="col-md-3">
                      <label>EWB Date</label>
                      <input type="date" class="form-control" name="ewb_dt" id="ewb_dt" value="<?php echo htmlspecialchars(substr($val('ewb_dt',''),0,10)); ?>">
                    </div>
                    <div class="col-md-3">
                      <label>Valid Upto</label>
                      <input type="date" class="form-control" name="ewb_valid_upto" id="ewb_valid_upto" value="<?php echo htmlspecialchars(substr($ewb_valid_upto_disp,0,10)); ?>">
                    </div>
                  </div>

                  <p class="help-block" style="margin-top:10px;">If you enter E-Way Bill, ensure Dispatch details match (vehicle/transporter) if available.</p>
                </div>
              </div>
            </div>

          </div>
        </div>
        <!-- ================= /Dispatch / E-Way Bill ================= -->

        <div style="margin-top:10px;">
          <button name="submit" class="btn btn-primary" type="submit" value="submit">UPDATE</button>
          <a class="btn btn-default" href="<?php echo htmlspecialchars($src_loc); ?>" style="margin-left:6px;">CANCEL</a>
        </div>

      </form>

    </div>
  </main>

  <!-- Party Modal (copied from dc-add.php) -->
  <div class="modal fade" id="PartyModal" role="dialog" style="z-index:10000;">
    <div class="modal-dialog modal-md">
      <div class="modal-content">
        <div class="modal-header" style="background:#ed7c65;">
          <button type="button" class="clos" data-dismiss="modal" style="color:red; float:right; font:18px bold;">X</button>
          <h4 class="modal-title" style="color:#FFFFFF;">Select Party</h4>
        </div>
        <div class="modal-body" style="height:480px;">
          <div class="container-fluid">
            <ul class="nav nav-tabs nav-justified" id="mytab">
              <li class="active" style="font-size:18px;"><a data-toggle="tab" href="#log">Search</a></li>
            </ul>

            <div class="tab-content" style="margin-top:3px;">
              <div id="log" class="tab-pane fade in active">
                <div class="row">
                  <div class="col-md-2"><b>Name:</b></div>
                  <div class="col-md-8">
                    <input id="srch_cust_name" name="srch_cust_name" placeholder="Name" type="text" value="">
                    <button onclick="searchName()"><span class="glyphicon glyphicon-search" aria-hidden="true"></span></button>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-2"><b>Contact:</b></div>
                  <div class="col-md-8">
                    <input type="text" id="srch_cust_number" name="srch_cust_number" placeholder="Phone Number" value=""/>
                    <button onclick="searchPhone()"><span class="glyphicon glyphicon-search" aria-hidden="true"></span></button>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-2"><b>Email:</b></div>
                  <div class="col-md-8">
                    <input type="text" id="srch_cust_email" name="srch_cust_email" placeholder="Email" value=""/>
                    <button onclick="searchEmail()"><span class="glyphicon glyphicon-search" aria-hidden="true"></span></button>
                  </div>
                </div>

                <hr>
                <div id="searchOutput" style="width:auto; height:auto; display:none; z-index:1; border:1px solid gray;"></div>
              </div>

            </div>
          </div>

        </div>
      </div>
    </div>
  </div>

  <!-- Item Modal (copied from dc-add.php) -->
  <div class="modal fade" id="ItemModal" tabindex="-1" role="dialog" aria-labelledby="ItemModalLabel">
    <div class="modal-dialog modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header" style="background:#ed7c65;">
          <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color:#fff;"><span aria-hidden="true">&times;</span></button>
          <h4 class="modal-title" id="ItemModalLabel" style="color:#fff;">Select Item</h4>
        </div>

        <div class="modal-body">
          <div class="row">
            <div class="col-md-6">
              <input type="text" class="form-control" id="itemSearchQuery" placeholder="Search by item name / id" />
            </div>
            <div class="col-md-2">
              <button type="button" class="btn btn-primary" id="btnItemSearch">Search</button>
            </div>
            <div class="col-md-4">
              <span id="itemResultHelp" class="help-block" style="display:none;"></span>
            </div>
          </div>

          <div class="row" style="margin-top:10px;">
            <div class="col-md-12">
              <select id="itemSearchResults" class="form-control" size="12" style="width:100%;"></select>
            </div>
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-success" id="btnAddSelectedItem" disabled>Add</button>
        </div>
      </div>
    </div>
  </div>


<script>
var poRowCounter = <?php echo (int)$rowCounter; ?>;
var itemCache = Object.create(null);

function limit12(el){
  if (!el) return;
  var v = String(el.value || '');
  if (v.length > 12) el.value = v.slice(0, 12);
}

function showTotal(t) {
  var qtyEl  = document.getElementById('quantity_' + t);
  var rateEl = document.getElementById('item_price_' + t);
  var gstEl  = document.getElementById('itemGST_' + t);

  var qty  = parseFloat((qtyEl && qtyEl.value)  ? qtyEl.value  : '0');
  var rate = parseFloat((rateEl && rateEl.value) ? rateEl.value : '0');
  var gst  = parseFloat((gstEl && gstEl.value)  ? gstEl.value  : '0');

  var sub = qty * rate;
  document.getElementById('itemSubTotal_' + t).innerHTML = (Math.round(sub * 100) / 100).toFixed(2);

  var total = sub + (sub * gst / 100);
  document.getElementById('itemTotal_' + t).innerHTML = (Math.round(total * 100) / 100).toFixed(2);
}

// saleBS-update style: existing rows toggle del/upd; new rows are removed
function toggleDeleteRow(t){
  var $row = $('#prodRow_' + t);
  var $rs  = $('#rec_status_' + t);
  if ($row.length === 0 || $rs.length === 0) return;

  var cur = String($rs.val() || 'upd').toLowerCase();

  if (cur === 'new') {
    // Newly added row: remove from DOM
    $row.remove();
    return;
  }

  if (cur === 'del') {
    $rs.val('upd');
    $row.removeClass('row-del');
  } else {
    $rs.val('del');
    $row.addClass('row-del');
  }
}


function addItemLineRow(it) {
  poRowCounter++;
  var t = poRowCounter;

  var itemId   = it.item_id || '';
  var name     = it.item_name || it.item_disp_name || '';
  var uom      = it.item_uom || '';
  var hsn      = it.hsn_code || '';
  var price    = it.item_pur_price || '';
  var gst      = it.gst || '';

  // prevent duplicates by item_id
  if (itemId) {
    var exists = $('#js1 input[name="item_id[]"]').filter(function(){ return this.value == itemId; }).length;
    if (exists) {
      alert('Item already added. Please change quantity in existing line.');
      return;
    }
  }

  var $tr = $('<tr/>', { id: 'prodRow_' + t });

  $tr.append($('<td/>').append(
    $('<input/>', { type:'hidden', name:'rec_status[]', id:'rec_status_' + t, value:'new' }),
    $('<input/>', { type:'hidden', name:'item_srl_no[]', id:'item_srl_no_' + t, value:'0' }),
    $('<input/>', { type:'hidden', name:'ref_order_details_id[]', value:'' }),
    $('<input/>', { type:'hidden', name:'discount_mode[]', value:'' }),
    $('<input/>', { type:'hidden', name:'discount_amt[]', value:'0' }),
    $('<input/>', { type:'hidden', name:'discount_pct[]', value:'' }),

    $('<input/>', { type:'hidden', name:'item_id[]', value:itemId }),
    $('<input/>', { type:'text', class:'input-md', readonly:true, id:'item_name_' + t, name:'item_name[]', value:name }),
    $('<textarea/>', {
      id: 'item_note_' + t,
      class: 'form-control form-control-lg',
      name: 'item_note[]'
      <?php if ($allow_remark_item === 'N') echo ", style: 'display:none'"; ?>
    })
  ));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'text', class:'input-md fld12', readonly:true, maxlength:12, size:12,
                    id:'hsn_sac_' + t, name:'hsn_sac[]', value:hsn })
  ));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'text', class:'input-md fld12', readonly:true, maxlength:12, size:12,
                    id:'uom_' + t, name:'uom[]', value:uom })
  ));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.01', min:'0', class:'input-md fld12',
                    id:'item_price_' + t, name:'item_price[]', value:price })
  ));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.001', min:'0', class:'input-md fld12',
                    id:'quantity_' + t, name:'quantity[]', value:'1' })
  ));

  $tr.append($('<td/>').append(
    $('<span/>', { id:'itemSubTotal_' + t }).text('0.00')
  ));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.01', min:'0', class:'input-md fld12',
                    id:'itemGST_' + t, name:'itemGST[]', value:gst })
  ));

  $tr.append($('<td/>').append(
    $('<span/>', { id:'itemTotal_' + t }).text('0.00')
  ));

  $tr.append($('<td/>').append(
    $('<button/>', { type:'button', class:'btn btn-danger btn-xs' })
      .text('X')
      .on('click', function(){ toggleDeleteRow(t); })
  ));

  $('#js1').append($tr);

  $('#item_price_' + t + ', #quantity_' + t + ', #itemGST_' + t).on('input', function(){
    limit12(this);
    showTotal(t);
  });

  showTotal(t);
}

(function(){
  // Wire recalculation on existing rows
  $('#js1').find('tr[id^="prodRow_"]').each(function(){
    var rid = this.id;
    var t = rid.split('_')[1];
    $('#item_price_' + t + ', #quantity_' + t + ', #itemGST_' + t).on('input', function(){
      limit12(this);
      showTotal(t);
    });
  });

  // Item modal behavior
  $('#ItemModal').on('shown.bs.modal', function(){
    $('#itemSearchQuery').val('').focus();
    $('#itemSearchResults').empty();
    $('#btnAddSelectedItem').prop('disabled', true);
    $('#itemResultHelp').hide().text('');
    itemCache = Object.create(null);
  });

  $('#itemSearchResults').on('change', function(){
    $('#btnAddSelectedItem').prop('disabled', this.selectedIndex < 0);
  });

  $('#btnItemSearch').on('click', function(){
    var q = $('#itemSearchQuery').val().trim();
    var biz_id = $('#biz_id').val();
    var $sel = $('#itemSearchResults');
    var $help = $('#itemResultHelp');

    $sel.empty();
    $('#btnAddSelectedItem').prop('disabled', true);
    $help.hide().text('');
    itemCache = Object.create(null);

    $.ajax({
      url: 'dc-item-searched-list-ajax.php',
      method: 'POST',
      dataType: 'json',
      data: { biz_id: biz_id, q: q }
    }).done(function(resp){
      if (!resp || !resp.ok) {
        $help.text((resp && resp.msg) ? resp.msg : 'Search failed.').show();
        return;
      }
      if (!resp.items || !resp.items.length) {
        $help.text('No items found for "' + q + '".').show();
        return;
      }

      resp.items.forEach(function(it){
        var itemId = String(it.item_id || '');
        if (!itemId) return;
        itemCache[itemId] = it;

        var text = '[' + itemId + '] ' + (it.item_name || it.item_disp_name || '') +
                   (it.item_uom ? (' (' + it.item_uom + ')') : '');
        $sel.append($('<option/>').val(itemId).text(text));
      });

      $help.text(resp.items.length + ' item(s) found').show();
      $sel.prop('selectedIndex', 0).trigger('change').focus();

    }).fail(function(){
      $help.text('Network error while searching.').show();
    });
  });

  $('#btnAddSelectedItem').on('click', function(){
    var itemId = String($('#itemSearchResults').val() || '');
    if (!itemId) return;
    var it = itemCache[itemId];
    if (!it) return;
    addItemLineRow(it);
    $('#ItemModal').modal('hide');
  });

  $('#itemSearchQuery').on('keydown', function(e){
    if (e.keyCode === 13) { e.preventDefault(); $('#btnItemSearch').click(); }
  });
  $('#itemSearchResults').on('dblclick', function(){
    $('#btnAddSelectedItem').click();
  });
})();
</script>

<script>
function togglePIQuoteFields() {
  var num = $.trim($('#sup_doc_num').val() || '');
  var $date = $('#sup_doc_date');
  if (num.length > 0) {
    $date.prop('disabled', false).prop('required', true);
    if (!$date.val()) {
      var today = new Date().toISOString().slice(0, 10);
      $date.val(today);
    }
  } else {
    $date.prop('required', false).prop('disabled', true).val('');
  }
}

$(function(){
  togglePIQuoteFields();
  $('#sup_doc_num').on('input', togglePIQuoteFields);
});
</script>

<script>
(function(){
  function setRequired(sel, req){
    var $el = $(sel);
    if (!$el.length) return;
    if (req) $el.attr('required','required');
    else $el.removeAttr('required');
  }

  function showPanels(mode){
    var showDispatch = (mode === 'DISPATCH' || mode === 'BOTH');
    var showEwb      = (mode === 'EWB' || mode === 'BOTH');

    $('#panelDispatch').toggle(showDispatch);
    $('#panelEwb').toggle(showEwb);

    setRequired('#transport_mode', showDispatch);
    setRequired('#ewb_num', showEwb);
    setRequired('#ewb_dt', showEwb);

    var tm = ($('#transport_mode').val() || '').toUpperCase();
    var requireVehicle = showDispatch && (tm === 'ROAD');
    setRequired('#vehicle_no', requireVehicle);

    if (!showDispatch) {
      setRequired('#vehicle_no', false);
      setRequired('#transport_doc_no', false);
      setRequired('#transport_doc_dt', false);
    }
  }

  $(document).on('change', 'input[name="dispatch_mode"]', function(){
    showPanels($(this).val());
  });
  $(document).on('change', '#transport_mode', function(){
    var mode = $('input[name="dispatch_mode"]:checked').val() || 'NONE';
    showPanels(mode);
  });

  $(function(){
    showPanels($('input[name="dispatch_mode"]:checked').val() || 'NONE');

    // init ship-to
    diffShipping(document.getElementById('diff_ship'));
    if ($('#diff_ship').is(':checked')) {
      $('#btnCopyBillToShip').show();
    }
  });
})();
</script>

<script>
function calcNetAmountFromTable(){
  var net = 0;
  $('#js1 tr[id^="prodRow_"]').each(function(){
    var rid = this.id;
    var t = rid.split('_')[1];
    var rs = String($('#rec_status_' + t).val() || 'upd').toLowerCase();
    if (rs === 'del') return;

    var txt = $('#itemTotal_' + t).text() || '0';
    var v = parseFloat(String(txt).replace(/,/g,''));
    if (!isNaN(v)) net += v;
  });
  return Math.round(net * 100) / 100;
}

$(function(){
  function openEwbPanel(){
    $('input[name="dispatch_mode"][value="EWB"]').prop('checked', true).trigger('change');
    $('#panelEwb').show();
    $('#collapseEwb').collapse('show');
    $('html, body').animate({ scrollTop: $('#panelEwb').offset().top - 80 }, 300);
    $('#ewb_num').focus();
  }

  $('#dc_update_form').on('submit', function(e){
    var net = calcNetAmountFromTable();

    var ewbNum = $.trim($('#ewb_num').val() || '');
    if (ewbNum !== '') return true;

    if (net > 50000) {
      var msg = "Net amount (including GST) is \u20B9" + net.toFixed(2) +
                " which is above \u20B950,000.\n" +
                "E-Way Bill details may be required.\n\n" +
                "Submit without entering E-Way Bill details?";

      if (!window.confirm(msg)) {
        e.preventDefault();
        openEwbPanel();
        return false;
      }
    }

    return true;
  });
});
</script>

</body>
</html>
