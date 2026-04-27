<?php
// 2025-04-28: Sales Update - Converted to PDO + Added Different Ship To Address
// 2025-10-13: Add Ledger Journal Update
// 2026-02-08: Changing UI to Cards, Item Selection and Round off.
 
ob_start();
session_start();
include 'include/param.php';
include 'include/dbo.php';
include 'include/session.php';

include 'include/item.php';
include 'include/stock_journal.php';
include 'include/ledger_journal.php'; // NEW: to mirror add flow

checksession();
$debug = 0;
$dbh = new dbo();
$item_obj = new Item();
$stk_j = new Stock_Journal($dbh);

$txn_type = "SALES";
$doc_type   = "SALES";

$biz_id     = $_SESSION['biz_id'];
$login_user = $_SESSION['pos_login'];
$dtm        = getLocalDtm();

$src_loc    = $_POST['src_loc'] ?? 'bill-manage.php';
$upd_inv_id = (int)($_POST['update_id'] ?? 0);

include 'company-info.php';
include 'config-doc-entry-info.php' ;   // input ( $biz_id and $doc_type) - output ($allow_remark_txn ;$allow_remark_item ) ;

// ===== Fetch Invoice Header (prepared) =====
$stmt = $dbh->prepare("SELECT * FROM table_invoice_header WHERE biz_id = ? AND invoice_id = ?");
$stmt->execute([$biz_id, $upd_inv_id]);
$head_row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$head_row) {
    echo "No records fetched..Contact support";
    exit(1);
}

// ===== Fetch Invoice Details =====
$stmt_det = $dbh->prepare("SELECT * FROM table_invoice_details WHERE parent_invoice_id = ?
	ORDER BY CASE
		WHEN item_type = 'CHARGE'    THEN 2
		WHEN item_type = 'ROUND_OFF' THEN 3
		ELSE 1
	END,invoice_details_id" );

$stmt_det->execute([$upd_inv_id]);
$det_rows = $stmt_det->fetchAll(PDO::FETCH_ASSOC);
$det_num_rows = count($det_rows);

//========== DELETE Invoice ======
// ----- Hard delete handler (guarded by money_txn_alloc) -----
if (isset($_POST['delete']) && $_POST['delete'] === '1') {
	
    try {
        // Block if any receipt allocated to this invoice
        if (has_receipts_alloc_on_invoice($dbh, $biz_id, $upd_inv_id)) {
            echo "<div class='alert alert-danger' style='margin:15px;'>
                    Deletion blocked: one or more Receipt Vouchers are allocated to
                    Invoice <b>".htmlspecialchars($head_row['invoice_num'], ENT_QUOTES)."</b> (Receipt->Allocation).
                    Please delete/adjust those allocations first, then try again. To return <a href='$src_loc'> Click Here </a>
                  </div>";
            exit;
        }

        $dbh->beginTransaction();

//	Log Deletion Action 
			// BEFORE you modify/delete anything:
			$hdr = $dbh->prepare("SELECT * FROM table_invoice_header WHERE biz_id=? AND invoice_id=?");
			$hdr->execute([$biz_id, $upd_inv_id]);
			$headerRow = $hdr->fetch(PDO::FETCH_ASSOC);

			$det = $dbh->prepare("SELECT * FROM table_invoice_details WHERE parent_invoice_id=? ORDER BY item_srl_no");
			$det->execute([$upd_inv_id]);
			$detailRows = $det->fetchAll(PDO::FETCH_ASSOC);

			// Inside the same transaction as the delete:
			log_delete(
				$dbh,
				$biz_id,
				'SALES_INV',
				(int)$upd_inv_id,
				$login_user,
				$_SERVER['REMOTE_ADDR'] ?? null,
				[
					'header'  => $headerRow,
					'details' => $detailRows
				]
			);


        // 1) Reverse ledger posting for this invoice (net to zero)
        if (class_exists('Ledger_Journal')) {
            $lj = new Ledger_Journal($dbh);
            // Build reversal lines and post them; no new lines after (pure reversal)
            $rev = $lj->buildReversalLines($biz_id, 'SalesInv', (int)$upd_inv_id);
            if (count($rev) >= 2) {
                $lj->postDoubleEntry(
                    $biz_id,
                    $head_row['invoice_dt'],
                    'SalesInv',
                    (int)$upd_inv_id,
                    $head_row['invoice_num'].' (DEL)',
                    $login_user,
                    $rev
                );
            }
        }

        // 2) Restore stock for each inventory line (skip CHARGE)
        $st = $dbh->prepare("SELECT invoice_details_id, item_id, item_type, qty
                               FROM table_invoice_details
                              WHERE parent_invoice_id = ?");
        $st->execute([$upd_inv_id]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            if (strtoupper($r['item_type'] ?? '') !== 'CHARGE' && strtoupper($r['item_type'] ?? '') !== 'ROUND_OFF' && (float)$r['qty'] > 0) {
                $qty = $item_obj->addItemQty($dbh, $biz_id, (int)$r['item_id'], (float)$r['qty']);
                $stk_j->insert_stock_journal(
                    $biz_id, (int)$r['item_id'], 0, (float)$r['qty'], $qty,
                    "Sale Delete: ".$head_row['invoice_num'],
                    (int)$upd_inv_id, (int)$r['invoice_details_id'], $login_user, $dtm
                );
            }
        }

        // 3) Delete detail rows, then header
        $dbh->prepare("DELETE FROM table_invoice_details WHERE parent_invoice_id = ?")->execute([$upd_inv_id]);
        $dbh->prepare("DELETE FROM table_invoice_header  WHERE invoice_id = ? AND biz_id = ?")->execute([$upd_inv_id, $biz_id]);

        $dbh->commit();
        header("Location: ". $src_loc);
        exit;

    } catch (Throwable $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        error_log("SALE-DELETE: $biz_id:$upd_inv_id:".$e->getMessage());
        echo "<div class='alert alert-danger' style='margin:15px;'>
                Error deleting invoice: ".htmlspecialchars($e->getMessage(), ENT_QUOTES)."
              </div>";
        exit;
    }
}


//======= Update Save Invoice ==========
$save_stage = 0 ;
if (isset($_POST['save_sale_update']) && $_POST['save_sale_update'] === '1') {
    try {
        $dbh->beginTransaction();

        // --- Header inputs ---
        $party_state  = $_POST["party_state"] ?? '';
        $gst_txn_type = (strlen(trim($party_state)) == 0 || $comp_state == $party_state) ? "local" : "interstate";

        $voucher_num  = trim($_POST["voucher_num"] ?? '');
        $voucher_date = $_POST["voucher_date"] ?? date('Y-m-d');
        $ord_ref_num  = trim($_POST["ord_ref_num"] ?? '');
        $ord_ref_date = $_POST["ord_ref_date"] ?? null;

        $party_id     = (int)($_POST["party_id"] ?? 0);
        $party_name   = $_POST["party_name"] ?? '';
        $party_address= $_POST["party_address"] ?? '';
        $party_pincode= $_POST["party_pincode"] ?? '';
        $party_phone  = $_POST["party_phone"] ?? '';
        $party_gstin  = $_POST["party_gstin"] ?? '';
        $remark_txn   = $_POST["remark_txn"] ?? '';

        // 1) Voucher number uniqueness within biz (excluding current invoice)
        $chk = $dbh->prepare("SELECT 1 FROM table_invoice_header WHERE biz_id = ? AND invoice_num = ? AND invoice_id <> ? LIMIT 1");
        $chk->execute([$biz_id, $voucher_num, $upd_inv_id]);
        if ($chk->fetchColumn()) {
            throw new RuntimeException("Invoice number already exists. Please change it.");
        }
		$save_stage = 1 ;
        // 2) Update header (prepared)
        $head_sql = "UPDATE table_invoice_header SET 
            invoice_num = ?, invoice_dt = ?, ref_doc_no = ?, ref_doc_date = ?, note = ?, 
            invoice_cust_id = ?, cust_name = ?, bill_to_address = ?, bill_to_state = ?, 
            bill_to_pincode = ?, bill_to_phone = ?, bill_to_gstin = ?, gst_txn_type = ?
            WHERE invoice_id = ?";
        $stmt = $dbh->prepare($head_sql);
        $stmt->execute([
            $voucher_num, $voucher_date, $ord_ref_num, $ord_ref_date, $remark_txn,
            $party_id, $party_name, $party_address, $party_state,
            $party_pincode, $party_phone, $party_gstin, $gst_txn_type,
            $upd_inv_id
        ]);
		$save_stage = 2 ;
		
        // 3) Update Ship-To
        if (!empty($_POST['diff_ship'])) {
            $shp_party_name   = $_POST["party2_name"] ?? '';
            $shp_party_address= $_POST["party2_address"] ?? '';
            $shp_party_state  = $_POST["party2_state"] ?? '';
            $shp_party_pincode= $_POST["party2_pincode"] ?? '';
            $shp_party_phone  = $_POST["party2_phone"] ?? '';
            $shp_party_gstin  = $_POST["party2_gstin"] ?? '';

            $upd_ship_sql = "UPDATE table_invoice_header SET 
                diff_shp_add = 'Y', shp_party_name = ?, shp_address = ?, shp_state = ?, 
                shp_pincode = ?, shp_phone = ?, shp_gstin = ?
                WHERE invoice_id = ?";
            $stmt = $dbh->prepare($upd_ship_sql);
            $stmt->execute([
                $shp_party_name, $shp_party_address, $shp_party_state,
                $shp_party_pincode, $shp_party_phone, $shp_party_gstin,
                $upd_inv_id
            ]);
        } else {
            $upd_ship_sql = "UPDATE table_invoice_header SET 
                diff_shp_add = 'N', shp_party_name = '', shp_address = '', shp_state = '', 
                shp_pincode = '', shp_phone = '', shp_gstin = ''
                WHERE invoice_id = ?";
            $stmt = $dbh->prepare($upd_ship_sql);
            $stmt->execute([$upd_inv_id]);
        }
		$save_stage = 3 ;

        // 4) Process line items
        $outp = 0.0;
        $total_cgst = 0.0;
        $total_sgst = 0.0;
        $total_igst = 0.0;
        $total_gst_amt = 0.0;
		$round_off_amt = 0.0;
        $effective_lines = 0;

        $n = is_array($_POST["item_id"] ?? null) ? count($_POST["item_id"]) : 0;

        for ($i = 0; $i < $n; $i++) {
            $rec_status   = $_POST['rec_status'][$i] ?? 'upd';   // new | upd | del
            $item_type    = $_POST['item_type'][$i] ?? '';       // ITEM_TYPE :  CHARGE | ROUND_OFF | GOODS
            $item_id      = (int)($_POST['item_id'][$i] ?? 0);
            $item_name    = $_POST['item_name'][$i] ?? '';
            $remark_item  = $_POST['remark_item'][$i] ?? '';
            $hsn_sac      = $_POST['hsn_sac'][$i] ?? '';
            $uom          = $_POST['uom'][$i] ?? '';
            $std_price    = (float)($_POST['item_price'][$i] ?? 0);
            $sale_qty      = (float)($_POST['quantity'][$i] ?? 0);
            $item_gst     = (float)($_POST['itemGST'][$i] ?? 0);

            // Sanity guards
            if ($sale_qty < 0) $sale_qty = 0;
			if ($item_type !== 'ROUND_OFF' && $std_price < 0) $std_price = 0;


            $discount_mode = $_POST['discMode'][$i] ?? '';
            $discAmt       = (float)($_POST['discAmt'][$i] ?? 0);

            $discount_amt = 0.0;
            $discount_pct = 0.0;
            if ($discount_mode === 'AMT') {
				if ($discAmt < 0) $discAmt = 0;
				if ($discAmt > $std_price) $discAmt = $std_price;
				
				
                $discount_amt = $discAmt;
                $finalPrice   = $std_price - $discAmt;
            } elseif ($discount_mode === 'PCT') {
				 if ($discAmt < 0) $discAmt = 0;
				 if ($discAmt > 100) $discAmt = 100;
				
                $discount_pct = $discAmt;
                $finalPrice   = $std_price - ($std_price * $discAmt) / 100.0;
            } else {
                $finalPrice   = $std_price;
            }
			
			// Existing negative ROUND_OFF line should not display as zero
			if ($item_type !== 'ROUND_OFF' && $finalPrice < 0) {
				$finalPrice = 0;
			}


			$is_inventory_item = ($item_type !== 'CHARGE' && $item_type !== 'ROUND_OFF');

			
            // Delete line: restore stock only for inventory items
            if ($rec_status === "del") {
                $invoice_details_id = (int)($_POST['invoice_details_id'][$i] ?? 0);

                // Remove line
                $dbh->prepare("DELETE FROM table_invoice_details WHERE invoice_details_id = ?")
                    ->execute([$invoice_details_id]);

                // Stock correction: deleting a sale line => add stock back
                if ($is_inventory_item && $sale_qty > 0) {
                    $qty = $item_obj->addItemQty($dbh, $biz_id, $item_id, $sale_qty);
                    $stk_j->insert_stock_journal(
                        $biz_id, $item_id, 0, $sale_qty, $qty,
                        "Sale Line Deleted:$voucher_num", $upd_inv_id, $invoice_details_id, $login_user, $dtm
                    );
                }
                // Skip totals for deleted lines
                continue;
            }

            // Compute amounts for new/upd
            $subTotal = $finalPrice * $sale_qty;

            if ($gst_txn_type === 'local') {
                $cgst = $subTotal * ($item_gst / 200.0);
                $sgst = $subTotal * ($item_gst / 200.0);
                $igst = 0.0;
            } else {
                $igst = $subTotal * ($item_gst / 100.0);
                $cgst = 0.0;
                $sgst = 0.0;
            }
            $gst_amt   = $cgst + $sgst + $igst;


            // UPDATE existing line
            if ($rec_status === "upd") {
                $invoice_details_id = (int)($_POST['invoice_details_id'][$i] ?? 0);
                $old_qty = (float)($_POST['old_qty'][$i] ?? 0);

                $det_sql = "UPDATE table_invoice_details SET 
                    item_id = ?, item_type = ?, item_name = ?, item_note = ?, uom = ?, qty = ?, price = ?, 
                    discount_mode = ?, discount_amt = ?, discount_pct = ?, total_amt = ?, 
                    hsn_code = ?, gst_pct = ?, CGST = ?, SGST = ?, IGST = ?, gst_amt = ?
                    WHERE invoice_details_id = ?";
                $stmt = $dbh->prepare($det_sql);
                $stmt->execute([
                    $item_id, $item_type, $item_name, $remark_item, $uom, $sale_qty, $std_price,
                    $discount_mode, $discount_amt, $discount_pct, $subTotal,
                    $hsn_sac, $item_gst, $cgst, $sgst, $igst, $gst_amt,
                    $invoice_details_id
                ]);

                // Stock: if qty changed and not a charge, neutralize old then apply new
                if ($is_inventory_item && $old_qty != $sale_qty) {
                    if ($old_qty > 0) {
                        $qty = $item_obj->addItemQty($dbh, $biz_id, $item_id, $old_qty);
                        $stk_j->insert_stock_journal($biz_id, $item_id, 0, $old_qty, $qty,
                            "Sale Update Old Qty:$voucher_num", $upd_inv_id, $invoice_details_id, $login_user, $dtm);
                    }
                    if ($sale_qty > 0) {
                        $qty = $item_obj->reduceItemQty($dbh, $biz_id, $item_id, $sale_qty);
                        $stk_j->insert_stock_journal($biz_id, $item_id, $sale_qty, 0, $qty,
                            "Sale Update New Qty:$voucher_num", $upd_inv_id, $invoice_details_id, $login_user, $dtm);
                    }
                }
            }

            // INSERT new line
            if ($rec_status === "new") {
                $item_srl_no = $i + 1;
                $det_sql = "INSERT INTO table_invoice_details
                    (biz_id, parent_invoice_id, item_srl_no, item_id, item_type, item_name, item_note, uom, qty, price,
                     discount_mode, discount_amt, discount_pct, total_amt, hsn_code, gst_pct, CGST, SGST, IGST, gst_amt)
                    VALUES (?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $dbh->prepare($det_sql);
                $stmt->execute([
                    $biz_id, $upd_inv_id, $item_srl_no, $item_id, $item_type, $item_name, $remark_item, $uom, $sale_qty, $std_price,
                    $discount_mode, $discount_amt, $discount_pct, $subTotal, $hsn_sac, $item_gst, $cgst, $sgst, $igst, $gst_amt
                ]);

                $invoice_detail_id = (int)$dbh->lastInsertId();

                if ($is_inventory_item && $sale_qty > 0) {
                    $qty = $item_obj->reduceItemQty($dbh, $biz_id, $item_id, $sale_qty);
                    $stk_j->insert_stock_journal($biz_id, $item_id, $sale_qty, 0, $qty,
                        "Sale Item:$voucher_num", $upd_inv_id, $invoice_detail_id, $login_user, $dtm);
                }
            }

            // Totals for non-deleted lines
			if ($item_type === 'ROUND_OFF') {
				$round_off_amt += $subTotal;
			} else {
				$outp += $subTotal;
				$total_cgst += $cgst;
				$total_sgst += $sgst;
				$total_igst += $igst;
				$total_gst_amt += $gst_amt;
			}

            // a line counts as effective if not zeroed out AND not deleted
			if ($rec_status !== 'del' && $item_type !== 'ROUND_OFF' && ( $sale_qty > 0 || $item_type === 'CHARGE' )) {
				$effective_lines++;
			}
        }

        if ($effective_lines === 0) {
            throw new RuntimeException("Add at least one valid item (qty > 0).");
        }
		$save_stage = 4 ;
        // 5) Update header totals (rounded)
        $untaxed  = round((float)$outp, 2);
        $taxTotal = round((float)$total_gst_amt, 2);
        $grand    = round($untaxed + $taxTotal + $round_off_amt, 2); // nearest rupee

        $update_sql = "UPDATE table_invoice_header
                       SET total_amt = ?, CGST = ?, SGST = ?, IGST = ?, total_tax = ?, net_amt = ?
                       WHERE invoice_id = ?";
        $stmt = $dbh->prepare($update_sql);
        $stmt->execute([
            $untaxed, round($total_cgst, 2), round($total_sgst, 2), round($total_igst, 2),
            $taxTotal, $grand, $upd_inv_id
        ]);

		$save_stage = 5 ;
        // 6) Ledger journal (mirror add flow). Assumes postDoubleEntry handles idempotency for same source.


            $L_SALES = ledger_id_by_name($dbh, $biz_id, 'Sales Revenue');
            $L_CGST  = ($total_cgst > 0) ? ledger_id_by_name($dbh, $biz_id, 'Output CGST') : null;
            $L_SGST  = ($total_sgst > 0) ? ledger_id_by_name($dbh, $biz_id, 'Output SGST') : null;
            $L_IGST  = ($total_igst > 0) ? ledger_id_by_name($dbh, $biz_id, 'Output IGST') : null;
			$L_ROUND = ($round_off_amt != 0.0) ? ledger_id_by_name($dbh, $biz_id, 'Rounding Difference') : null;
			
            $lines = [];
            // Dr Customer
            $L_AR = $party_id ;
            $lines[] = ['ledger_id'=>$L_AR, 'debit'=>$grand];
            // Cr Sales
            if ($untaxed != 0.0)            $lines[] = ['ledger_id'=>$L_SALES, 'credit'=>$untaxed];
            if ($L_CGST && $total_cgst!=0.0)$lines[] = ['ledger_id'=>$L_CGST,  'credit'=>round($total_cgst,2)];
            if ($L_SGST && $total_sgst!=0.0)$lines[] = ['ledger_id'=>$L_SGST,  'credit'=>round($total_sgst,2)];
            if ($L_IGST && $total_igst!=0.0)$lines[] = ['ledger_id'=>$L_IGST,  'credit'=>round($total_igst,2)];

			if ($L_ROUND && $round_off_amt > 0) {
				$lines[] = ['ledger_id' => $L_ROUND, 'credit' => round($round_off_amt, 2)];
			} elseif ($L_ROUND && $round_off_amt < 0) {
				$lines[] = ['ledger_id' => $L_ROUND, 'debit' => abs(round($round_off_amt, 2))];
			}
						
			$lj = new Ledger_Journal($dbh);

			// Ledger Journal update: Replace-by-reference policy (Option A).
			// We hard-delete prior ledger_journal rows for SalesInv+invoice_id (ignoring src_txn_num),
			// then insert the new balanced lines and recompute running balances forward.
			// NOTE: We pass src_txn_num = null to avoid duplicates when voucher_num changes.			
			
			$lj->UpdateByRef(
				biz_id:       $biz_id,
				jrnl_date:    $voucher_date,
				src_txn_type: 'SalesInv',
				src_txn_id:   (int)$upd_inv_id,
				src_txn_num:  null ,   // scope: pass null to delete all batches for this invoice_id instead of $voucher_num
				created_by:   $login_user,
				new_lines:    $lines
			);
		$save_stage = 6 ;

        $dbh->commit();
        header("location: $src_loc");
        exit;

    } catch (Throwable $e) {
        if ($dbh->inTransaction()) { $dbh->rollBack(); }
        error_log("SALE-UPDATE: ".$biz_id.":".$upd_inv_id.":".$e->getMessage());
        echo "<div class='alert alert-danger'> Error In Updating Sales Voucher:$save_stage ".$e->getMessage()."</div>";
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <title>Sales Voucher Update</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=yes">

  <!-- Bootstrap CSS v5.2.1 -->
  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" type="text/css" rel="stylesheet">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

	<style>
	.page-actions {
		position: sticky; top: 0; z-index: 1000;
		background: #ccf2ff; /* same as body */
		padding: 8px 0;
	  }

	.fld8 { width: 8ch; max-width: 8ch; }
	.fld12 { width: 12ch; max-width: 12ch; }

	.totbox { font-weight: bold; }
	.totrow td { background:#f5f5f5; }
		
	td.disc-mode { min-width: 90px; }
	td.disc-val  { min-width: 90px; }
	td.disc-mode select { height: 30px; padding: 4px 6px; }
	  
	  
/* ===== Customer panel normalization (same as add) ===== */
		.customer-panel .form-group {
		  margin-bottom: 6px;
		}

		.customer-panel .control-label {
		  text-align: left;
		  padding-left: 6px;
		  padding-top: 4px;
		  white-space: nowrap;
		}

		.customer-panel .control-label.small {
		  width: 40px;
		  min-width: 40px;
		  max-width: 40px;
		}

		.customer-panel .form-control {
		  height: 30px;
		  padding: 4px 6px;
		  width: 100%;
		}

		.bill-ship-header {
		  display: flex;
		  align-items: center;
		  justify-content: space-between;
		  min-height: 30px;
		  margin-bottom: 4px;
		}

		.bill-ship-title {
		  font-weight: bold;
		  color: #337ab7;
		}
	  

		#ItemDetailsPanel .form-control {
		  height: 28px;
		  padding: 3px 6px;
		  font-size: 12px;
		}

		#ItemDetailsPanel textarea {
		  resize: vertical;
		}

		#ItemDetailsPanel th {
		  white-space: nowrap;
		  font-size: 12px;
		}

		#ItemDetailsPanel td {
		  vertical-align: middle;
		}
	  
	</style>

  <script>

  function searchName(){
    var biz_id = document.getElementById('biz_id').value ;
    var cust_name = document.getElementById('srch_cust_name').value ;
    var act_grp = "customer" ;
    $.ajax({
      url: "party-search-name-ajax.php",
      type: "post",
      data: {p_act_grp:act_grp, p_biz_id:biz_id, p_cust_name:cust_name},
      success: function (response) {
        $("#searchOutput").html(response);
        $("#searchOutput").css("display","block");
      },
      error: function(jqXHR, textStatus, errorThrown) {
        console.log(textStatus, errorThrown);
      }
    });
  }

  function searchPhone(){
    var biz_id = document.getElementById('biz_id').value ;
    var cust_phone = document.getElementById('srch_cust_number').value ;
    var act_grp = "customer" ;
    $.ajax({
      url: "party-search-contact-ajax.php",
      type: "post",
      data: {p_act_grp:act_grp, p_biz_id:biz_id, p_cust_number:cust_phone},
      success: function (response) {
        $("#searchOutput").html(response);
        $("#searchOutput").css("display","block");
      },
      error: function(jqXHR, textStatus, errorThrown) {
        console.log(textStatus, errorThrown);
      }
    });
  }

  function searchEmail(){
    var biz_id = document.getElementById('biz_id').value ;
    var cust_email = document.getElementById('srch_cust_email').value ;
    var act_grp = "customer" ;
    $.ajax({
      url: "party-search-email-ajax.php",
      type: "post",
      data: {p_act_grp:act_grp, p_biz_id:biz_id, p_cust_email:cust_email},
      success: function (response) {
        $("#searchOutput").html(response);
        $("#searchOutput").css("display","block");
      },
      error: function(jqXHR, textStatus, errorThrown) {
        console.log(textStatus, errorThrown);
      }
    });
  }

  function set_party(val){
    var str_array = val.split(":");
    $.ajax({
      type: 'post',
      url: 'party-info-fetch-ajax.php',
      data: { cust_id: str_array[0] },
      success: function (response) {
        var obj = JSON.parse(response);
        document.getElementById("party_id").value = obj.account_id;
        document.getElementById("party_name").value = obj.account_name;
        document.getElementById("party_name_dup").value = obj.account_name;
        document.getElementById("party_address").value = obj.address;
        document.getElementById("party_phone").value = obj.phone_num;
        document.getElementById("party_state").value = obj.state;
        document.getElementById("party_pincode").value = obj.pincode;
        document.getElementById("party_gstin").value = obj.gstin;
        document.getElementById("ord_ref_num").focus();
      }
    });
  }

  function addParty(){
    var c_name = $("#cst_name").val();
    var c_phone = $("#cst_number").val();
    var c_add = $("#cst_address").val();
    var c_email = $("#cst_email").val();
    var c_gstin = $("#cst_gstin").val();
    var c_state = $("#cst_state").val();

    $.ajax({
      type: 'post',
      url: 'bill-customer-add-ajax.php',
      data: {
        act_grp : "customer",
        cst_name:c_name,
        cst_phone:c_phone,
        cst_add: c_add,
        cst_email: c_email,
        cst_gstin: c_gstin,
        cst_state: c_state
      },
      success: function (response) {
        set_party(response);
      }
    });
    return false;
  }

  function toggleParty(cb_party_det){
    var x = document.getElementById("PartyDetails");
    x.style.display = cb_party_det.checked ? "block" : "none";
  }

  function showShipTo(ck_box){
    var x = document.getElementById("ShipTo");
    x.style.display = ck_box && ck_box.checked ? "block" : "none";
  }
  </script>
</head>

<body style="background-color:#ccf2ff;">
<div class="container-fluid">
  <div>
    <?php include 'header.inc.php'; ?>
  </div>
  <center><h3 class="text-primary" style="margin-top:50px;">Sales Voucher Update</h3></center>

<div class="row page-actions">
  <div class="col-sm-3">
    <a href='<?php echo htmlspecialchars($src_loc, ENT_QUOTES);?>' style='border-radius:0'>❮ Back</a>
  </div>
  <div class="col-sm-9 text-right">
	<form id="deleteForm" method="post">
        <input type="hidden" id="biz_id" name="biz_id" value="<?php echo (int)$biz_id;?>">
		<input type="hidden" name="update_id" value="<?php echo (int)$upd_inv_id; ?>">	
		<input type="hidden" name="src_loc" value="<?php echo htmlspecialchars($src_loc, ENT_QUOTES); ?>">
  
		<button type="button" class="btn btn-danger"
				onclick="$('#confirmDeleteModal').modal('show')">
		<span class="glyphicon glyphicon-trash" aria-hidden="true"></span> Delete </button>
    </form>		
  </div>
</div>

</div>

<main>
  <div class="container container-md mt-6 p-4">
    <form id="saleForm" method='POST'>
		<input type="hidden" name="save_sale_update" value="1">
		<input type="hidden" id="biz_id" name="biz_id" value="<?php echo (int)$biz_id;?>">
		<input type="hidden" id="update_id" name="update_id" value="<?php echo (int)$upd_inv_id;?>">
		<input type="hidden" name="src_loc" value="<?php echo htmlspecialchars($src_loc, ENT_QUOTES);?>">


      <div class="form-group row">
        <label class="control-label col-md-2" for="voucher_num">Sale Voucher No<span style="color:red">*</span></label>
        <div class="col-md-3">
          <input name="voucher_num" id="voucher_num" required class="input-md" type="text" value="<?php echo htmlspecialchars($head_row['invoice_num'], ENT_QUOTES);?>">
        </div>
        <label class="control-label col-md-2" for="voucher_date">Transaction Date<span style="color:red">*</span></label>
        <div class="col-md-3">
          <input name="voucher_date" id="voucher_date" required class="input-md" type="date" value="<?php echo htmlspecialchars($head_row['invoice_dt'], ENT_QUOTES); ?>">
        </div>
      </div>
	  
     <div class="row" style="margin-bottom:2px;margin-top:10px;">
        <label class="control-label col-md-2" for="ord_ref_num">Order Ref Number</label>
        <div class="col-md-2">
          <input name="ord_ref_num" id="ord_ref_num" class="input-md" type="text" value="<?php echo htmlspecialchars($head_row['ref_doc_no'], ENT_QUOTES);?>">
        </div>
        <div class="col-md-1"></div>
        <label class="control-label col-md-2" for="ord_ref_date">Order Date</label>
        <div class="col-md-3">
          <input type="date" name="ord_ref_date" id="ord_ref_date" class="input-md" value="<?php echo htmlspecialchars($head_row['ref_doc_date'], ENT_QUOTES);?>">
        </div>
      </div>

	<div class="panel panel-default" id="CustomerPanel">

		  <div class="panel-heading"
			   style="display:flex; align-items:center; gap:10px;">

			<strong style="min-width:140px;">Party Details</strong>

			<button type="button"
					class="btn btn-info btn-xs"
					data-toggle="modal"
					data-target="#PartyModal">
			  Select Party
			</button>

			<span>
			  <b>ID:</b>
			  <input readonly id="party_id" name="party_id"
					 style="width:50px; height:22px;"
					 value="<?php echo htmlspecialchars($head_row['invoice_cust_id'], ENT_QUOTES); ?>">
			</span>

			<span>
			  <b>Name:</b>
			  <input readonly id="party_name" name="party_name"
					 style="width:220px; height:22px;"
					 value="<?php echo htmlspecialchars($head_row['cust_name'], ENT_QUOTES); ?>">
			</span>

			<span style="margin-left:auto;">
			  <label style="font-weight:normal; margin:0;">
				<input type="checkbox" checked
					   onchange="toggleParty(this)">
				Show/Hide Party Details
			  </label>
			</span>
		</div>

	<div class="panel-body customer-panel" id="PartyDetails" style="padding:10px;">

	<div class="row">

    <!-- ===== BILL TO ===== -->
    <div class="col-md-6">
      <div style="border:1px solid #ddd; padding:12px; border-radius:4px; background:#fafafa;">

        <div class="bill-ship-header">
          <span class="bill-ship-title">Bill To</span>
        </div>

        <div class="row form-group">
          <label class="col-md-2 control-label">Name</label>
          <div class="col-md-10">
            <input readonly class="form-control" id="party_name_dup"
                   name="party_name_dup"
                   value="<?php echo htmlspecialchars($head_row['cust_name'], ENT_QUOTES); ?>">
          </div>
        </div>

        <div class="row form-group">
          <label class="col-md-2  control-label">Address</label>
          <div class="col-md-10">
            <textarea readonly class="form-control" rows="2" id="party_address"
              name="party_address"><?php echo htmlspecialchars($head_row['bill_to_address'], ENT_QUOTES); ?></textarea>
          </div>
        </div>

        <div class="row form-group">
          <label class="col-md-2  control-label">State</label>
          <div class="col-md-5">
            <input readonly class="form-control" id="party_state" 
                   name="party_state"
                   value="<?php echo htmlspecialchars($head_row['bill_to_state'], ENT_QUOTES); ?>">
          </div>

          <label class="col-md-2 control-label small">PIN</label>
          <div class="col-md-4">
            <input readonly class="form-control" id="party_pincode"
                   name="party_pincode"
                   value="<?php echo htmlspecialchars($head_row['bill_to_pincode'], ENT_QUOTES); ?>">
          </div>
        </div>

        <div class="row form-group">
          <label class="col-md-2  control-label">GSTIN</label>
          <div class="col-md-5">
            <input readonly class="form-control" id="party_gstin"
                   name="party_gstin"
                   value="<?php echo htmlspecialchars($head_row['bill_to_gstin'], ENT_QUOTES); ?>">
          </div>

          <label class="col-md-2 control-label small">Phone</label>
          <div class="col-md-4">
            <input readonly class="form-control" id="party_phone"
                   name="party_phone"
                   value="<?php echo htmlspecialchars($head_row['bill_to_phone'], ENT_QUOTES); ?>">
          </div>
        </div>
      </div>
    </div>

<!---- ========= Ship To ================= ---->

	<div class="col-md-6">
	  <div style="border:1px solid #ddd; padding:12px; border-radius:4px; background:#fdfdfd;">

		<div class="bill-ship-header">
		  <span class="bill-ship-title">Ship To</span>

		  <span>
			<label style="font-weight:normal; margin:0;">
			  <input type="checkbox" name="diff_ship" id="diff_ship"
					 onchange="showShipTo(this)"
					 <?php if ($head_row['diff_shp_add']=='Y') echo 'checked'; ?>>
			  Different address
			</label>
		  </span>
		</div>

		<div id="ShipTo" style="display:<?php echo ($head_row['diff_shp_add']=='Y')?'block':'none'; ?>; margin-top:10px;">
			  <div class="row form-group">
				<label class="col-md-2 control-label">Name</label>
				<div class="col-md-10">
				  <input name="party2_name" id="party2_name" class="form-control" value="<?php echo htmlspecialchars($head_row['shp_party_name'], ENT_QUOTES);?>">
				</div>
			  </div>

			  <div class="row form-group">
				<label class="col-md-2 control-label">Address</label>
				<div class="col-md-10">
				  <textarea name="party2_address" id="party2_address"
							class="form-control" rows="2"><?php echo htmlspecialchars($head_row['shp_address'], ENT_QUOTES);?></textarea>
				</div>
			  </div>

			  <div class="row form-group">
				<label class="col-md-2 control-label">State</label>
				<div class="col-md-5">
				  <input name="party2_state" id="party2_state" class="form-control" value="<?php echo htmlspecialchars($head_row['shp_state'], ENT_QUOTES);?>">
				</div>

				<label class="col-md-1 control-label">PIN</label>
				<div class="col-md-4">
				  <input name="party2_pincode" id="party2_pincode" class="form-control" value="<?php echo htmlspecialchars($head_row['shp_pincode'], ENT_QUOTES);?>">
				</div>
			  </div>

			  <div class="row form-group">
				<label class="col-md-2 control-label">GSTIN</label>
				<div class="col-md-5">
				  <input name="party2_gstin" id="party2_gstin" class="form-control" value="<?php echo htmlspecialchars($head_row['shp_gstin'], ENT_QUOTES);?>">
				</div>
				<label class="col-md-1 control-label">Phone</label>
				<div class="col-md-4">
				  <input name="party2_phone" id="party2_phone" class="form-control" value="<?php echo htmlspecialchars($head_row['shp_phone'], ENT_QUOTES);?>">
				</div>

			  </div>
		</div>
	  </div>
	</div>
    </div>
    </div>
   </div>

      <div class="row" style="margin-bottom:2px;margin-top:10px;<?php if ($allow_remark_txn=='N') echo 'display:none;';?>">
        <label class="control-label col-md-2" for="remark_txn">Remark</label>
        <div class="col-md-10">
          <input name="remark_txn" id="remark_txn" class="form-control" type="text" value="<?php echo htmlspecialchars($head_row['note'], ENT_QUOTES);?>">
        </div>
      </div>



<div class="panel panel-default" id="ItemDetailsPanel" style="margin-top:12px;">

  <div class="panel-heading"
       style="display:flex; align-items:center; justify-content:space-between; padding:8px 12px;">
    <strong>Item Details</strong>

    <div>
      <button type="button"
              class="btn btn-warning btn-xs"
              id="btnAddRoundOff">
        Add Round Off
      </button>

      <button type="button"
              class="btn btn-primary btn-xs"
              id="btnOpenItemModal"
              data-toggle="modal"
              data-target="#ItemModal"
              style="margin-left:6px;">
        Add Item
      </button>
    </div>
  </div>

  <div class="panel-body" style="padding:8px;">

    <div class="table-responsive">
      <table class="table table-hover table-condensed" style="margin-bottom:6px;">
        <thead>
          <tr>
            <th>Name</th>
            <th>HSN/SAC</th>
            <th>UoM</th>
            <th>Price</th>
            <th>Disc. Mode</th>
            <th>Disc. Amt</th>
            <th>Quantity</th>
            <th>Sub Total</th>
            <th>GST</th>
            <th>Line Total</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="js1">

              <?php
                $t = 1;
                foreach ($det_rows as $det_row) {
                  // compute on load
                  $stdPrice = (float)$det_row['price'];
                  $discount_mode = $det_row['discount_mode'];
                  if ($discount_mode === 'AMT') {
                    $discAmt = (float)$det_row['discount_amt'];
                    $finalPrice = $stdPrice - $discAmt;
                  } elseif ($discount_mode === 'PCT') {
                    $discAmt = (float)$det_row['discount_pct'];
                    $finalPrice = $stdPrice - ($stdPrice * $discAmt)/100.0;
                  } else {
                    $discAmt = 0.0;
                    $finalPrice = $stdPrice;
                  }
				  if ($det_row['item_type'] !== 'ROUND_OFF' && $finalPrice < 0) {
						$finalPrice = 0;
					}
                  $subTotal = $finalPrice * (float)$det_row['qty'];
                  $gst_pct  = (float)$det_row['gst_pct'];
                  $itemTotal = $subTotal + ($subTotal*$gst_pct)/100.0;

                  echo "<tr id='prodRow_$t'>";
                  echo "<td>";
                  echo "<input id='rec_status_$t' name='rec_status[]' value='upd' type='hidden'>";
                  echo "<input id='item_type_$t'  name='item_type[]' value='".htmlspecialchars($det_row['item_type'], ENT_QUOTES)."' type='hidden'>"; // NEW
                  echo "<input id='item_id_$t' name='item_id[]' value='".(int)$det_row['item_id']."' type='hidden'>";
                  echo "<input id='invoice_details_id_$t' name='invoice_details_id[]' value='".(int)$det_row['invoice_details_id']."' type='hidden'>";
                  echo "<input readonly id='item_name_$t' class='form-control form-control-lg' name='item_name[]' value='".htmlspecialchars($det_row['item_name'], ENT_QUOTES)."'>";
                  echo "<textarea id='remark_item_$t' class='form-control form-control-lg' ".($allow_remark_item=='N' ? "style=\"display:none\"" : "")." name='remark_item[]'>".htmlspecialchars($det_row['item_note'] ?? '', ENT_QUOTES)."</textarea>";
                  echo "</td>";

                  echo "<td><input id='hsn_sac_$t' class='form-control form-control-lg fld8' name='hsn_sac[]' value='".htmlspecialchars($det_row['hsn_code'], ENT_QUOTES)."'></td>";
                  echo "<td><input readonly id='uom_$t' class='form-control form-control-lg fld8' name='uom[]' value='".htmlspecialchars($det_row['uom'], ENT_QUOTES)."'></td>";

                  echo "<td><input id='item_price_$t' class='form-control form-control-lg fld12' onchange='showTotalSafe($t)' name='item_price[]' type='number' step='0.01' value='".htmlspecialchars($det_row['price'], ENT_QUOTES)."'></td>";

                  echo "<td>";
                  echo "<select id='discMode_$t' class='form-control form-control-lg' name='discMode[]' style='width:80px;' onchange='showTotalSafe($t)'>";
                  foreach ($param_discount_mode as $key=>$value){
                    $selected = ($det_row['discount_mode']===$key) ? "selected" : "";
                    echo "<option $selected value='$key'>$value</option>";
                  }
                  echo "</select>";
                  echo "</td>";

                  echo "<td><input id='discAmt_$t' class='form-control form-control-lg fld8' onchange='showTotalSafe($t)' name='discAmt[]' type='number' step='0.01' value='".htmlspecialchars($discAmt, ENT_QUOTES)."'></td>";

                  echo "<td>";
                  echo "<input id='old_qty_$t' class='form-control form-control-lg' type='hidden' name='old_qty[]' value='".htmlspecialchars($det_row['qty'], ENT_QUOTES)."'>";
                  // Disable qty edit for CHARGE rows

				  $ro = ($det_row['item_type'] === 'CHARGE' || $det_row['item_type'] === 'ROUND_OFF') ? "readonly" : "";				  
                  
				  echo "<input id='quantity_$t' class='form-control form-control-lg fld12' onchange='showTotalSafe($t)' $ro name='quantity[]' type='number' step='0.001' value='".($det_row['item_type']==='CHARGE' ? 1 : htmlspecialchars($det_row['qty'], ENT_QUOTES))."'>";
                  echo "</td>";

                  echo "<td><div id='itemSubTotal_".$t."'>".round($subTotal,2)."</div></td>";
                  echo "<td><input id='itemGST_$t' class='form-control form-control-lg fld8' name='itemGST[]' type='number' value='".htmlspecialchars($det_row['gst_pct'], ENT_QUOTES)."' onchange='showTotalSafe($t)'></td>";
                  echo "<td><div id='itemTotal_".$t."'>".round($itemTotal,2)."</div></td>";

                  echo "<td><button type='button' id='remove_$t' class='btn btn-danger' onclick='remove(".$t.")'>X</button></td>";
                  echo "</tr>";
                  $t++;
                }
              ?>
			  
              </tbody>
			<tfoot>
			  <tr class="totrow">
				<td colspan="7" class="text-right totbox">Totals:</td>
				<td class="totbox"><span id="tot_taxable">0.00</span></td>
				<td class="totbox"><span id="tot_gst">0.00</span></td>
				<td class="totbox"><span id="tot_net">0.00</span></td>
				<td></td>
			  </tr>

			  <tr class="totrow">
				<td colspan="11" class="text-right">
				  <span style="margin-right:15px;">CGST: <b><span id="tot_cgst">0.00</span></b></span>
				  <span style="margin-right:15px;">SGST: <b><span id="tot_sgst">0.00</span></b></span>
				  <span style="margin-right:15px;">IGST: <b><span id="tot_igst">0.00</span></b></span>
				</td>
			  </tr>
			</tfoot>
			  
            </table>
          </div>
        </div>

		<div class="panel-footer" style="padding:8px; text-align:right;">
			<button name="submit"
					class="btn btn-success"
					type="submit"
					value="submit">
			  Save Voucher
			</button>
		</div>

      </div>  <!-- End of ItemDetailsPanel -->
    </form>
  </div>
</main>

<footer></footer>

<script>
  function showProduct(str,t) {
    $.ajax({
      url  : "sale-get-item-data-ajax.php", // FIXED: use sale endpoint
      type : "post",
      data : {q:str, t:t },
      success: function (response) {
        var obj = JSON.parse(response);
        document.getElementById("item_name_"+t).value = obj.item_name;
        document.getElementById("hsn_sac_"+t).value  = obj.hsn_code;
        document.getElementById("uom_"+t).value      = obj.item_uom;
        document.getElementById("item_price_"+t).value = obj.item_mrp;
        document.getElementById("itemGST_"+t).value  = obj.gst;
        document.getElementById("item_type_"+t).value = obj.item_type;

        if (obj.item_type === "CHARGE") {
          document.getElementById("quantity_"+t).value = 1;
          document.getElementById("quantity_"+t).readOnly = true;
        } else {
          document.getElementById("quantity_"+t).value = 0;
          document.getElementById("quantity_"+t).readOnly = false;
        }
      }
    });
  }

  function remove(t) {
    if (t == 0) {
      alert("No Table Rows to Remove");
      return;
    }
    var row = document.getElementById('prodRow_'+t);
    var rec_status_el = document.getElementById('rec_status_'+t);
    var btn = document.getElementById('remove_'+t);

    if (rec_status_el.value === "new") {
      row.remove();
    } else if (rec_status_el.value === "upd") {
      rec_status_el.value = "del";
      row.style.backgroundColor = "red";
      btn.innerHTML = "UnDelete";
    } else if (rec_status_el.value === "del") {
      rec_status_el.value = "upd";
      row.style.backgroundColor = "white";
      btn.innerHTML = "Remove";
    }
	
	recalcAllTotalsSale();
	updateRoundOffIfPresent();
  
  }
</script>

<div class="modal fade" id="PartyModal" role="dialog" style="z-index:10000;">
  <div class="modal-dialog modal-md">
    <div class="modal-content">
      <div class="modal-header" style="background:#ed7c65;">
        <button type="button" class="clos" data-dismiss="modal" style="color:red; float:right; font:18px bold;">X</button>
        <h4 class="modal-title" style="color:#FFFFFF;">Select Customer</h4>
      </div>
      <div class="modal-body" style="height:380px;">
        <div class="container-fluid">
          <ul class="nav nav-tabs nav-justified" id="mytab">
            <li class="active" style="font-size:18px;"><a data-toggle="tab" href="#log">Search</a></li>
<!--            <li style="font-size:18px;"><a data-toggle="tab" href="#customer_add">Add</a></li> -->
          </ul>

          <div class="tab-content" style="margin-top:3px;">
            <div id="log" class="tab-pane fade in active">
              <div class="row"><div class="col-md-2"><b>Name:</b></div>
                <div class="col-md-8">
                  <input id="srch_cust_name" name="srch_cust_name" placeholder="Name" type="text" value="">
                  <button type="button" onclick="searchName()"> <i class="fa fa-list" aria-hidden="true"></i> </button>
                </div>
              </div>
              <div class="row"><div class="col-md-2"><b>Contact:</b></div>
                <div class="col-md-8">
                  <input type="text" id="srch_cust_number" name="srch_cust_number" placeholder="Phone Number" value=""/>
                  <button type="button" onclick="searchPhone()"> <i class="fa fa-list" aria-hidden="true"></i> </button>
                </div>
              </div>
              <div class="row"><div class="col-md-2"><b>Email:</b></div>
                <div class="col-md-8">
                  <input type="text" id="srch_cust_email" name="srch_cust_email" placeholder="Email" value=""/>
                  <button type="button" onClick="searchEmail()"> <i class="fa fa-list" aria-hidden="true"></i> </button>
                </div>
              </div>
              <hr>
              <div id="searchOutput" style="width:auto; height:auto; display:none; z-index:1; border:1px solid gray;"></div>
            </div>

            <div id="customer_add" class="tab-pane fade" style="margin-left: 70px;">
              <div class="form-group row">
                <div class="col-md-5">
                  <p><b>Name:</b><input id="cst_name" name="cst_name" placeholder="Name" class="form-control input-md" type="text"></p>
                </div>
                <div class="col-md-5">
                  <p><b>Contact:</b> <input type="text" id="cst_number" name="cst_number" class="form-control" placeholder="Phone Number" /></p>
                </div>
              </div>
              <div class="form-group row">
                <div class="col-md-5">
                  <p><b>Address:</b> <input type="text" id="cst_address" name="cst_address" class="form-control" placeholder="Address" /></p>
                </div>
                <div class="col-md-5">
                  <p><b>State:</b>
                    <select class="form-control" id="cst_state" name="cst_state" required>
                      <option value="" disabled selected>Choose State</option>
                      <?php 
                        for ($i=0;$i<count($list_india_state); $i++) {
                          echo "<option value='$list_india_state[$i]'>$list_india_state[$i]</option>";
                        }
                      ?>
                    </select>
                  </p>
                </div>
                <div class="col-md-5">
                  <p><b>Email:</b> <input type="text" id="cst_email" name="cst_email" class="form-control" placeholder="Email" /></p>
                </div>
              </div>
              <div class="form-group row">
                <div class="col-md-5">
                  <p><b>GSTIN:</b> <input type="text" id="cst_gstin" name="cst_gstin" class="form-control" placeholder="GSTIN" /></p>
                </div>
              </div>
              <div class="form-group row">
                <div class="col-md-2"></div>
                <div class="col-md-5">
                  <button id="btn_cst_add" name="btn_cst_add" class="btn btn-primary btn-block" onClick='addParty()'>Submit</button>
                </div>
              </div>
            </div> <!-- /tab -->
          </div>
        </div>
      </div>
    </div>
  </div>
</div>


<div class="modal fade" id="confirmDeleteModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header" style="background:#d9534f;color:#fff;">
        <button type="button" class="close" data-dismiss="modal">×</button>
        <h4 class="modal-title">Delete 	this invoice?</h4>
      </div>
      <div class="modal-body">
        <p>This will restore stock and reverse ledger entries.</p>
        <p class="text-danger"><small>This action cannot be undone.</small></p>
        <p>Type <b><?php echo htmlspecialchars($head_row['invoice_num'], ENT_QUOTES); ?></b> to confirm:</p>
        <input id="delConfirmText" class="form-control" autocomplete="off">
      </div>
      <div class="modal-footer">
        <button class="btn btn-default" data-dismiss="modal">Cancel</button>
        <button class="btn btn-danger" onclick="submitDelete('deleteForm')" id="confirmDeleteBtn" disabled>Confirm Delete</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var must = "<?php echo addslashes($head_row['invoice_num']); ?>";
  $('#delConfirmText').on('input', function(){
    $('#confirmDeleteBtn').prop('disabled', this.value.trim() !== must);
  });
})();

function submitDelete(formId){
  var f = document.getElementById(formId);
  var hid = document.createElement('input');
  hid.type = 'hidden';
  hid.name = 'delete';
  hid.value = '1';
  f.appendChild(hid);
  f.submit();
}
</script>


<!--------------- Item Modal  --------------------->  

<div class="modal fade" id="ItemModal" tabindex="-1" role="dialog" aria-labelledby="ItemModalLabel">
  <div class="modal-dialog" role="document">
    <div class="modal-content">

      <div class="modal-header" style="background:#ed7c65;">
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
        <h4 class="modal-title" id="ItemModalLabel">Select Item</h4>
      </div>

      <div class="modal-body">
        <form class="form-inline" onsubmit="return false;">
          <div class="form-group">
            <input type="text" id="itemSearchQuery" class="form-control"
                   placeholder="Type item name/code…" style="min-width:280px;">
          </div>
          <button type="button" id="btnItemSearch" class="btn btn-default">Search</button>
        </form>

        <hr>

        <div class="form-group">
          <label for="itemSearchResults">Matches</label>
          <select id="itemSearchResults" class="form-control" size="10" style="width:100%;"></select>
          <span class="help-block" id="itemResultHelp" style="display:none;"></span>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" id="btnAddSelectedItem" class="btn btn-primary" disabled>Add Selected</button>
        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>

<!--------------- Item Modal  --------------------->  
<script>
/* ===== Item selection from Item-Modal ===== */

var saleRowCounter = <?php echo $det_num_rows + 1; ?>;
var saleItemCache = Object.create(null);
var allowRemarkItem = <?php echo json_encode((string)$allow_remark_item); ?>;

var updatingRoundOff = false;
var COMP_STATE = <?php echo json_encode((string)($comp_state ?? '')); ?>;

function norm(s){ return String(s||'').trim().toLowerCase(); }
function isLocalTxn(){
  var ps = norm($('#party_state').val());
  var cs = norm(COMP_STATE);
  if (!ps || !cs) return true;
  return ps === cs;
}

function sMoney2(x){
  var v = parseFloat(x);
  if (isNaN(v)) v = 0;
  return (Math.round(v * 100) / 100).toFixed(2);
}

function removeRowSale(t){
  var el = document.getElementById('prodRow_' + t);
  if (el) el.remove();
  recalcAllTotalsSale();
  updateRoundOffIfPresent();
}


function recalcAllTotalsSale(){
  var totTaxable=0, totGst=0, totNet=0, totC=0, totS=0, totI=0;

  $('#js1 tr[id^="prodRow_"]').each(function(){
    var t = this.id.split('_')[1];

	var recStatus = String($('#rec_status_' + t).val() || '').toLowerCase();
	if (recStatus === 'del') return;   // skip deleted rows from totals

    var sub = parseFloat($('#itemSubTotal_' + t).text() || '0');
    var net = parseFloat($('#itemTotal_' + t).text() || '0');

    if (isNaN(sub)) sub = 0;
    if (isNaN(net)) net = 0;

    var gst = net - sub;
    if (gst < 0) gst = 0; // roundoff and safety

    totTaxable += sub;
    totGst += gst;
    totNet += net;

    if (isLocalTxn()) { totC += gst/2.0; totS += gst/2.0; }
    else { totI += gst; }
  });

  $('#tot_taxable').text(sMoney2(totTaxable));
  $('#tot_gst').text(sMoney2(totGst));
  $('#tot_net').text(sMoney2(totNet));
  $('#tot_cgst').text(sMoney2(totC));
  $('#tot_sgst').text(sMoney2(totS));
  $('#tot_igst').text(sMoney2(totI));
}


function showTotalSafe(t){
  // This is your show Total(t), but made defensive and aligned with created inputs.
  var qty = parseFloat($('#quantity_' + t).val() || '0');
  var rate = parseFloat($('#item_price_' + t).val() || '0');
  var gstp = parseFloat($('#itemGST_' + t).val() || '0');

  var mode = String($('#discMode_' + t).val() || 'NONE').toUpperCase();
  var dval = parseFloat($('#discAmt_' + t).val() || '0');

  if (isNaN(qty) || qty < 0) qty = 0;
  if (isNaN(rate)) rate = 0;
  if (isNaN(gstp) || gstp < 0) gstp = 0;
  if (isNaN(dval) || dval < 0) dval = 0;

  // cap discount
  var disc = 0;
  if (mode === 'AMT') disc = dval;
  else if (mode === 'PCT') disc = rate * (dval / 100.0);

  if (disc < 0) disc = 0;
  if (rate > 0 && disc > rate) disc = rate;

  var finalRate = rate - disc;
  var subTotal = qty * finalRate;
  var tax = subTotal * (gstp / 100.0);
  var lineTotal = subTotal + tax;

  $('#itemSubTotal_' + t).text(sMoney2(subTotal));
  $('#itemTotal_' + t).text(sMoney2(lineTotal));
  
	recalcAllTotalsSale();
	updateRoundOffIfPresent();
}


function addSaleItemRow(it){
  saleRowCounter++;
  var t = saleRowCounter;

  var itemId = (it.item_id ?? '');
  var itemType = String(it.item_type || 'ITEM').toUpperCase();
  var name = it.item_name || it.item_disp_name || '';
  var uom = it.item_uom || '';
  var hsn = it.hsn_code || '';
  var price = (it.item_sale_price ?? it.item_mrp ?? it.item_std_price ?? 0);
  var gst = (it.gst ?? 0);

  // prevent duplicates by item_id (same behavior as quote-add)
  var itemIdStr = String(itemId ?? '').trim();
  if (itemIdStr !== '') {
    var exists = $('#js1 input[name="item_id[]"]').filter(function(){ return this.value == itemId; }).length;
    if (exists) { alert('Item already added. Change qty in existing line.'); return; }
  }

  var $tr = $('<tr/>', { id: 'prodRow_' + t });

  // Name col: hidden fields + visible name + optional remark_item
  var $nameTd = $('<td/>');
  $nameTd.append(
    $('<input/>', { type:'hidden', name:'rec_status[]', id:'rec_status_' + t, value:'new' }),
    $('<input/>', { type:'hidden', name:'item_id[]', id:'item_id_' + t, value:itemId }),
    $('<input/>', { type:'hidden', name:'item_type[]', id:'item_type_' + t, value:itemType }),
    $('<input/>', { type:'text', class:'input-md', readonly:true, name:'item_name[]', id:'item_name_' + t, value:name })
  );

  var $remark = $('<textarea/>', { class: 'form-control input-sm',  name: 'remark_item[]',
  id: 'remark_item_' + t,   placeholder: 'Item remark',   rows: 2,  style: 'min-width:180px; resize:vertical;' });
  if (String(allowRemarkItem).toUpperCase() === 'N') $remark.css('display','none');
  $nameTd.append('<br>', $remark);

  $tr.append($nameTd);

  // HSN
  $tr.append($('<td/>').append(
    $('<input/>', { type:'text', class:'input-md fld8', readonly:true, name:'hsn_sac[]', id:'hsn_sac_' + t, value:hsn })
  ));

  // UOM
  $tr.append($('<td/>').append(
    $('<input/>', { type:'text', class:'input-md fld8', readonly:true, name:'uom[]', id:'uom_' + t, value:uom })
  ));

  // Price
  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.01', min:'0', class:'input-md fld12', name:'item_price[]', id:'item_price_' + t, value:price })
  ));

  // Disc Mode
  $tr.append($('<td/>').append(
    $('<select/>', { class:'disc-mode', name:'discMode[]', id:'discMode_' + t })
      .append('<option value="NONE">NONE</option>')
      .append('<option value="AMT">AMT</option>')
      .append('<option value="PCT">PCT</option>')
  ));

  // Disc Amt/Pct input (same field as your server expects discAmt[])
  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.01', min:'0', class:'disc-val fld8', name:'discAmt[]', id:'discAmt_' + t, value:'0' })
  ));

  // Qty
  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.001', min:'0', class:'input-md fld12', name:'quantity[]', id:'quantity_' + t, value:'1' })
  ));

  // Subtotal (span)
  $tr.append($('<td/>').append(
    $('<span/>', { id:'itemSubTotal_' + t }).text('0.00')
  ));

  // GST %
  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.01', min:'0', class:'input-md fld8', name:'itemGST[]', id:'itemGST_' + t, value:gst })
  ));

  // Line total (span)
  $tr.append($('<td/>').append(
    $('<span/>', { id:'itemTotal_' + t }).text('0.00')
  ));

  // Actions
  $tr.append($('<td/>').append(
    $('<button/>', { type:'button', class:'btn btn-danger btn-xs' })
      .text('X')
      .on('click', function(){ removeRowSale(t); })
  ));

  $('#js1').append($tr);

  // CHARGE behavior (same spirit as your existing logic)
  if (itemType === 'CHARGE') {
		$('#quantity_' + t).val('1').prop('readonly', true);
		// usually no discount on charges
		$('#discMode_' + t).val('NONE').css({'pointer-events':'none','background':'#eee'});
		$('#discAmt_' + t).val('0').prop('readonly', true);
  }

	if (itemType === 'ROUND_OFF') {
	  $('#quantity_' + t).val('1').prop('readonly', true);
	  $('#itemGST_' + t).val('0').prop('readonly', true);
	  $('#discMode_' + t).val('NONE').css({'pointer-events':'none','background':'#eee'});
	  $('#discAmt_' + t).val('0').prop('readonly', true);

	  // allow negative
	  $('#item_price_' + t).attr('min', '-999999');
	}

	  // Bind recalculation events
	  $('#quantity_' + t + ', #item_price_' + t + ', #discMode_' + t + ', #discAmt_' + t + ', #itemGST_' + t)
		.on('input change', function(){ showTotalSafe(t); });

	  showTotalSafe(t);
}

/* ===== Item modal search behavior (copied from quote-add style) ===== */
(function(){
  $('#ItemModal').on('shown.bs.modal', function () {
    $('#itemSearchQuery').val('').focus();
    $('#itemSearchResults').empty();
    $('#btnAddSelectedItem').prop('disabled', true);
    $('#itemResultHelp').hide().text('');
    saleItemCache = Object.create(null);
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
    saleItemCache = Object.create(null);

    $.ajax({
      url: 'dc-item-searched-list-ajax.php',   // same endpoint used in quote-add
      method: 'POST',
      dataType: 'json',
      data: { biz_id: biz_id, q: q }
    }).done(function(resp){
      if (!resp || !resp.ok) { $help.text((resp && resp.msg) ? resp.msg : 'Search failed.').show(); return; }
      if (!resp.items || !resp.items.length) { $help.text('No items found for "' + q + '".').show(); return; }

      resp.items.forEach(function(it){
        var itemId = String(it.item_id || '');
        if (!itemId) return;
        saleItemCache[itemId] = it;

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
    var it = saleItemCache[itemId];
    if (!it) return;
    addSaleItemRow(it);
    $('#ItemModal').modal('hide');
  });

  $('#itemSearchQuery').on('keydown', function(e){
    if (e.keyCode === 13) { e.preventDefault(); $('#btnItemSearch').click(); }
  });
  $('#itemSearchResults').on('dblclick', function(){
    $('#btnAddSelectedItem').click();
  });
})();

/*** Submit guard: require at least one item row with non-zero ***/
$(function () {
  $('#saleForm').on('submit', function (e) {

    var $form = $(this);

    // Prevent double-submit after user confirms
    if ($form.data('saving') === true) {
      e.preventDefault();
      return false;
    }
	
	var partyName = $.trim($('#party_name').val() || '');

    if (partyName === '') {
      alert('Party must be selected.');
      $('#party_name').focus();
      e.preventDefault();
      return false;
    }

    var hasAnyLine = false;
    var hasValidLine = false;

    $('#js1 tr[id^="prodRow_"]').each(function () {

		var t = this.id.split('_')[1];

		var recStatus = String($('#rec_status_' + t).val() || '').toLowerCase();
		if (recStatus === 'del') return;

      hasAnyLine = true;


      var itemType = String($('#item_type_' + t).val() || '').toUpperCase();
      var qty = parseFloat($('#quantity_' + t).val() || '0');

      if (isNaN(qty)) qty = 0;

      // ROUND_OFF should not be treated as a real sale item
      if (itemType !== 'ROUND_OFF' && qty > 0) {
        hasValidLine = true;
        return false; // break loop
      }
    });

    if (!hasAnyLine) {
      alert('Add at least one item.');
      e.preventDefault();
      return false;
    }

    if (!hasValidLine) {
      alert('At least one item must have quantity greater than zero.');
      $('#js1 input[name="quantity[]"]').first().focus();
      e.preventDefault();
      return false;
    }

    // Final user confirmation
    if (!confirm('Proceed to save?')) {
      e.preventDefault();
      return false;
    }

    // User confirmed, now allow submit
    $form.data('saving', true);
    $('#saleForm button[type="submit"]').prop('disabled', true).text('Saving...');

    return true;
  });
});

</script>

<script>
$(function(){
  $('#btnAddRoundOff').on('click', function(){
    addRoundOffRowSale();
  });
});

function addRoundOffRowSale(){
  var existingT = findRoundOffT();
  if (existingT) { updateRoundOffIfPresent(); return; }

  // Adds a row via your modal-row builder function.
  // If your function name is addSaleItemRow(it), keep it.
  // If you used a different name, update below.
  var it = {
    item_id: 0,
    item_name: 'Round Off',
    item_uom: 'NOS',
    hsn_code: '',
    item_sale_price: 0,
    gst: 0,
    item_type: 'ROUND_OFF'
  };
  addSaleItemRow(it);
  updateRoundOffIfPresent();
}

function updateRoundOffIfPresent(){
  if (updatingRoundOff) return;
  var rt = findRoundOffT();
  if (!rt) return;

  updatingRoundOff = true;
  try {
    var baseNet = sumNetExcludingRoundOff();
    var rounded = Math.round(baseNet);             // nearest rupee
    var diff    = +(rounded - baseNet).toFixed(2); // can be negative

    // force basic fields
    $('#quantity_' + rt).val('1').prop('readonly', true);
    $('#itemGST_' + rt).val('0').prop('readonly', true);
	$('#discMode_' + rt).val('NONE').css({'pointer-events':'none','background':'#eee'});	
//    $('#discMode_' + rt).val('NONE').prop('disabled', true);
    $('#discAmt_' + rt).val('0').prop('readonly', true);

    // allow negative price for roundoff
    $('#item_price_' + rt).attr('min', '-999999').val(diff.toFixed(2));

    // recompute this row
    showTotalSafe(rt);
  } finally {
    updatingRoundOff = false;
  }
}

/* ===== Round Off logic (same idea as quote-add) ===== */

function findRoundOffT(){
  var found = null;
  $('#js1 tr[id^="prodRow_"]').each(function(){
    var t = this.id.split('_')[1];
    var typ = String($('#item_type_' + t).val() || '').toUpperCase();
    if (typ === 'ROUND_OFF') { found = t; return false; }
  });
  return found;
}

function sumNetExcludingRoundOff(){
  var sum = 0;
  $('#js1 tr[id^="prodRow_"]').each(function(){
    var t = this.id.split('_')[1];
    var typ = String($('#item_type_' + t).val() || '').toUpperCase();
    if (typ === 'ROUND_OFF') return;

    var net = parseFloat($('#itemTotal_' + t).text() || '0');
    if (!isNaN(net)) sum += net;
  });
  return sum;
}


</script>




</body>
</html>

<?php
function json_out($arr) {
    header('Content-Type: application/json');
    echo json_encode($arr);
    exit;
}

// same helper as add flow
function ledger_id_by_name(PDO $dbh, int $biz_id, string $ledger_name): int {
    $q = $dbh->prepare("SELECT account_id FROM account_ledger WHERE biz_id = :b AND account_name = :n LIMIT 1");
    $q->execute([':b'=>$biz_id, ':n'=>$ledger_name]);
    $id = $q->fetchColumn();
    if (!$id) { throw new RuntimeException("System ledger missing: {$ledger_name}"); }
    return (int)$id;
}
function has_receipts_alloc_on_invoice(PDO $dbh, int $biz_id, int $invoice_id): bool {
    $sql = " SELECT 1 FROM money_txn_alloc mta
			WHERE biz_id   = :b
			AND doc_type = 'SALES_INV'
			AND doc_id   = :invoice_id
			LIMIT 1 ";
    $st = $dbh->prepare($sql);
    $st->execute([':b'=>$biz_id, ':invoice_id'=>$invoice_id]);
    return (bool)$st->fetchColumn();
}


?>
