<?php
// Purchase Update - PDO + Modal Item Selection + Discount + Remarks + Round Off + Ledger Update

ob_start();
session_start();

include 'include/param.php';
include 'include/dbo.php';
include 'include/session.php';

include 'include/item.php';
include 'include/stock_journal.php';
include 'include/ledger_journal.php';

checksession();

$debug = 0;
$dbh = new dbo();
$item_obj = new Item();
$stk_j = new Stock_Journal($dbh);

$doc_type   = 'PURCHASE';
$txn_type   = 'PURCHASE';
$biz_id     = (int)($_SESSION['biz_id'] ?? 0);
$login_user = $_SESSION['pos_login'] ?? 'system';
$dtm        = getLocalDtm();

$src_loc    = $_POST['src_loc'] ?? 'purchase-manage.php';
$upd_inv_id = (int)($_POST['update_id'] ?? 0);

include 'company-info.php';

$allow_remark_txn  = 'Y';
$allow_remark_item = 'Y';
if (file_exists('config-doc-entry-info.php')) {
    include 'config-doc-entry-info.php';
    $allow_remark_txn  = $allow_remark_txn  ?? 'Y';
    $allow_remark_item = $allow_remark_item ?? 'Y';
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ledger_id_by_name(PDO $dbh, int $biz_id, string $ledger_name): int {
    $q = $dbh->prepare("SELECT account_id FROM account_ledger WHERE biz_id = :b AND account_name = :n LIMIT 1");
    $q->execute([':b' => $biz_id, ':n' => $ledger_name]);
    $id = $q->fetchColumn();
    if (!$id) {
        throw new RuntimeException("System ledger missing: {$ledger_name}");
    }
    return (int)$id;
}

function has_payments_alloc_on_purchase(PDO $dbh, int $biz_id, int $invoice_id): bool {
    // Same idea as sales delete guard. Adjust doc_type here if your allocation table uses a different value.
    $sql = "SELECT 1
              FROM money_txn_alloc
             WHERE biz_id = :b
               AND doc_type = 'PURCHASE'
               AND doc_id = :invoice_id
             LIMIT 1";
    $st = $dbh->prepare($sql);
    $st->execute([':b' => $biz_id, ':invoice_id' => $invoice_id]);
    return (bool)$st->fetchColumn();
}

function calc_purchase_gst_split(string $gst_txn_type, float $base, float $gst_pct): array {
    if ($gst_txn_type === 'local') {
        $cgst = $base * ($gst_pct / 200.0);
        $sgst = $base * ($gst_pct / 200.0);
        return [$cgst, $sgst, 0.0, $cgst + $sgst];
    }

    $igst = $base * ($gst_pct / 100.0);
    return [0.0, 0.0, $igst, $igst];
}

// ===== Fetch Invoice Header =====
$stmt = $dbh->prepare("SELECT * FROM table_invoice_header WHERE biz_id = ? AND invoice_id = ? AND txn_type = ?");
$stmt->execute([$biz_id, $upd_inv_id, $txn_type]);
$head_row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$head_row) {
    echo "No records fetched..Contact support";
    exit(1);
}

// ===== Fetch Invoice Details =====
$stmt_det = $dbh->prepare("SELECT * FROM table_invoice_details
                            WHERE parent_invoice_id = ?
                            ORDER BY CASE
                                WHEN item_type = 'CHARGE' THEN 2
                                WHEN item_type = 'ROUND_OFF' THEN 3
                                ELSE 1
                            END, invoice_details_id");
$stmt_det->execute([$upd_inv_id]);
$det_rows = $stmt_det->fetchAll(PDO::FETCH_ASSOC);
$det_num_rows = count($det_rows);

// ===== Delete Purchase Voucher =====
if (isset($_POST['delete']) && $_POST['delete'] === '1') {
    try {
        if (has_payments_alloc_on_purchase($dbh, $biz_id, $upd_inv_id)) {
            echo "<div class='alert alert-danger' style='margin:15px;'>
                    Deletion blocked: one or more payment/receipt allocations are linked to Purchase Voucher
                    <b>".h($head_row['invoice_num'])."</b>.
                    Please delete/adjust those allocations first. To return <a href='".h($src_loc)."'>Click Here</a>
                  </div>";
            exit;
        }

        $dbh->beginTransaction();

        if (function_exists('log_delete')) {
            $hdr = $dbh->prepare("SELECT * FROM table_invoice_header WHERE biz_id = ? AND invoice_id = ?");
            $hdr->execute([$biz_id, $upd_inv_id]);
            $headerRow = $hdr->fetch(PDO::FETCH_ASSOC);

            $det = $dbh->prepare("SELECT * FROM table_invoice_details WHERE parent_invoice_id = ? ORDER BY item_srl_no");
            $det->execute([$upd_inv_id]);
            $detailRows = $det->fetchAll(PDO::FETCH_ASSOC);

            log_delete(
                $dbh,
                $biz_id,
                'PURCHASE',
                (int)$upd_inv_id,
                $login_user,
                $_SERVER['REMOTE_ADDR'] ?? null,
                ['header' => $headerRow, 'details' => $detailRows]
            );
        }

        // Reverse ledger posting.
        if (class_exists('Ledger_Journal')) {
            $lj = new Ledger_Journal($dbh);
            $rev = $lj->buildReversalLines($biz_id, 'PURCHASE', (int)$upd_inv_id);
            if (count($rev) >= 2) {
                $lj->postDoubleEntry(
                    $biz_id,
                    $head_row['invoice_dt'],
                    'PURCHASE',
                    (int)$upd_inv_id,
                    $head_row['invoice_num'].' (DEL)',
                    $login_user,
                    $rev
                );
            }
        }

        // Reverse stock: purchase increased stock, so deletion should reduce it.
        $st = $dbh->prepare("SELECT invoice_details_id, item_id, item_type, qty
                               FROM table_invoice_details
                              WHERE parent_invoice_id = ?");
        $st->execute([$upd_inv_id]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $item_type = strtoupper((string)($r['item_type'] ?? ''));
            $is_inventory_item = ($item_type !== 'CHARGE' && $item_type !== 'ROUND_OFF');
            $qty_to_reverse = (float)($r['qty'] ?? 0);
            $item_id = (int)($r['item_id'] ?? 0);

            if ($is_inventory_item && $item_id > 0 && $qty_to_reverse > 0) {
                $qty = $item_obj->reduceItemQty($dbh, $biz_id, $item_id, $qty_to_reverse);
                $stk_j->insert_stock_journal(
                    $biz_id, $item_id, $qty_to_reverse, 0, $qty,
                    "Purchase Delete: ".$head_row['invoice_num'],
                    (int)$upd_inv_id, (int)$r['invoice_details_id'], $login_user, $dtm
                );
            }
        }

        $dbh->prepare("DELETE FROM table_invoice_details WHERE parent_invoice_id = ?")->execute([$upd_inv_id]);
        $dbh->prepare("DELETE FROM table_invoice_header WHERE invoice_id = ? AND biz_id = ?")->execute([$upd_inv_id, $biz_id]);

        $dbh->commit();
        header("Location: ".$src_loc);
        exit;

    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        error_log("PURCHASE-DELETE: {$biz_id}:{$upd_inv_id}:".$e->getMessage());
        echo "<div class='alert alert-danger' style='margin:15px;'>Error deleting purchase voucher: ".h($e->getMessage())."</div>";
        exit;
    }
}

// ===== Delete Purchase Voucher - END =====


// ===== Update Save Purchase Voucher =====
$save_stage = 0;
if (isset($_POST['save_purchase_update']) && $_POST['save_purchase_update'] === '1') {
    try {
        $dbh->beginTransaction();

        $vendor_state = trim((string)($_POST['vendor_state'] ?? ''));
        $gst_txn_type = ($vendor_state === '' || strtoupper(trim((string)$comp_state)) === strtoupper($vendor_state)) ? 'local' : 'interstate';

        $voucher_num      = trim((string)($_POST['voucher_num'] ?? ''));
        $voucher_date     = (string)($_POST['voucher_date'] ?? date('Y-m-d'));
        $sup_invoice_num  = trim((string)($_POST['sup_invoice_num'] ?? ''));
        $sup_invoice_date = null;

        if ($sup_invoice_num !== '') {
            $sup_invoice_date = trim((string)($_POST['sup_invoice_date'] ?? ''));
            if ($sup_invoice_date === '') {
                throw new RuntimeException('Supplier invoice date is required when supplier invoice number is entered.');
            }
        }

        $vendor_id      = (int)($_POST['vendor_id'] ?? 0);
        $vendor_name    = trim((string)($_POST['vendor_name'] ?? ''));
        $vendor_address = trim((string)($_POST['vendor_address'] ?? ''));
        $vendor_pincode = trim((string)($_POST['vendor_pincode'] ?? ''));
        $vendor_phone   = trim((string)($_POST['vendor_phone'] ?? ''));
        $vendor_gstin   = trim((string)($_POST['vendor_gstin'] ?? ''));
        $remark_txn     = trim((string)($_POST['remark_txn'] ?? ''));

        if ($vendor_id <= 0) {
            throw new RuntimeException('Select a supplier first.');
        }

        // Voucher number uniqueness, excluding current purchase voucher.
        $chk = $dbh->prepare("SELECT 1
                                FROM table_invoice_header
                               WHERE biz_id = ?
                                 AND txn_type = ?
                                 AND invoice_num = ?
                                 AND invoice_id <> ?
                               LIMIT 1");
        $chk->execute([$biz_id, $txn_type, $voucher_num, $upd_inv_id]);
        if ($chk->fetchColumn()) {
            throw new RuntimeException('Purchase voucher number already exists. Please change it.');
        }

        // Supplier invoice duplicate check, excluding current purchase voucher.
        if ($sup_invoice_num !== '' && $sup_invoice_date !== null) {
            $supChk = $dbh->prepare("SELECT invoice_num
                                       FROM table_invoice_header
                                      WHERE biz_id = :biz_id
                                        AND txn_type = :txn_type
                                        AND invoice_cust_id = :vendor_id
                                        AND ref_doc_no = :sup_invoice_num
                                        AND ref_doc_date = :sup_invoice_date
                                        AND invoice_id <> :invoice_id
                                      LIMIT 1");
            $supChk->execute([
                ':biz_id' => $biz_id,
                ':txn_type' => $txn_type,
                ':vendor_id' => $vendor_id,
                ':sup_invoice_num' => $sup_invoice_num,
                ':sup_invoice_date' => $sup_invoice_date,
                ':invoice_id' => $upd_inv_id
            ]);
            $existingVoucher = $supChk->fetchColumn();
            if ($existingVoucher) {
                throw new RuntimeException("Supplier invoice number/date already added in Purchase Voucher: {$existingVoucher}");
            }
        }

        $save_stage = 1;

        $head_sql = "UPDATE table_invoice_header SET
                        invoice_num = ?, invoice_dt = ?, ref_doc_no = ?, ref_doc_date = ?, note = ?,
                        invoice_cust_id = ?, cust_name = ?, bill_to_address = ?, bill_to_state = ?,
                        bill_to_pincode = ?, bill_to_phone = ?, bill_to_gstin = ?, gst_txn_type = ?
                     WHERE invoice_id = ? AND biz_id = ? AND txn_type = ?";
        $stmt = $dbh->prepare($head_sql);
        $stmt->execute([
            $voucher_num, $voucher_date, $sup_invoice_num, $sup_invoice_date, $remark_txn,
            $vendor_id, $vendor_name, $vendor_address, $vendor_state,
            $vendor_pincode, $vendor_phone, $vendor_gstin, $gst_txn_type,
            $upd_inv_id, $biz_id, $txn_type
        ]);

        $save_stage = 2;

        $outp = 0.0;
        $total_cgst = 0.0;
        $total_sgst = 0.0;
        $total_igst = 0.0;
        $total_gst_amt = 0.0;
        $round_off_amt = 0.0;
        $effective_lines = 0;
        $saved_line_count = 0;
        $item_srl_no = 0;

        $n = is_array($_POST['item_id'] ?? null) ? count($_POST['item_id']) : 0;

        for ($i = 0; $i < $n; $i++) {
            $rec_status   = $_POST['rec_status'][$i] ?? 'upd';
            $item_type    = strtoupper(trim((string)($_POST['item_type'][$i] ?? '')));
            $item_id      = (int)($_POST['item_id'][$i] ?? 0);
            $old_item_id  = (int)($_POST['old_item_id'][$i] ?? $item_id);
            $item_name    = trim((string)($_POST['item_name'][$i] ?? ''));
            $remark_item  = trim((string)($_POST['remark_item'][$i] ?? ''));
            $hsn_sac      = trim((string)($_POST['hsn_sac'][$i] ?? ''));
            $uom          = trim((string)($_POST['uom'][$i] ?? ''));
            $std_price    = (float)($_POST['item_price'][$i] ?? 0);
            $pur_qty      = (float)($_POST['quantity'][$i] ?? 0);
            $old_qty      = (float)($_POST['old_qty'][$i] ?? 0);
            $item_gst     = (float)($_POST['itemGST'][$i] ?? 0);

            if ($item_name === '') {
                continue;
            }

            if ($pur_qty < 0) $pur_qty = 0;
            if ($item_type !== 'ROUND_OFF' && $std_price < 0) $std_price = 0;
            if ($item_gst < 0) $item_gst = 0;

            $discount_mode = strtoupper(trim((string)($_POST['discMode'][$i] ?? 'NONE')));
            $discAmt = (float)($_POST['discAmt'][$i] ?? 0);
            $discount_amt = 0.0;
            $discount_pct = 0.0;

            if ($item_type === 'ROUND_OFF') {
                $pur_qty = 1;
                $item_gst = 0.0;
                $discount_mode = 'NONE';
                $finalPrice = $std_price;
            } elseif ($discount_mode === 'AMT') {
                if ($discAmt < 0) $discAmt = 0;
                if ($discAmt > $std_price) $discAmt = $std_price;
                $discount_amt = $discAmt;
                $finalPrice = $std_price - $discAmt;
            } elseif ($discount_mode === 'PCT') {
                if ($discAmt < 0) $discAmt = 0;
                if ($discAmt > 100) $discAmt = 100;
                $discount_pct = $discAmt;
                $finalPrice = $std_price - (($std_price * $discAmt) / 100.0);
            } else {
                $discount_mode = 'NONE';
                $finalPrice = $std_price;
            }

            if ($item_type !== 'ROUND_OFF' && $finalPrice < 0) {
                $finalPrice = 0;
            }

            $is_inventory_item = ($item_type !== 'CHARGE' && $item_type !== 'ROUND_OFF');
            $old_item_type = strtoupper(trim((string)($_POST['old_item_type'][$i] ?? $item_type)));
            $old_is_inventory_item = ($old_item_type !== 'CHARGE' && $old_item_type !== 'ROUND_OFF');

            if ($rec_status === 'del') {
                $invoice_details_id = (int)($_POST['invoice_details_id'][$i] ?? 0);

                $dbh->prepare("DELETE FROM table_invoice_details WHERE invoice_details_id = ? AND parent_invoice_id = ?")
                    ->execute([$invoice_details_id, $upd_inv_id]);

                // Purchase deletion reverses the old purchase stock by reducing stock.
                if ($old_is_inventory_item && $old_item_id > 0 && $old_qty > 0) {
                    $qty = $item_obj->reduceItemQty($dbh, $biz_id, $old_item_id, $old_qty);
                    $stk_j->insert_stock_journal(
                        $biz_id, $old_item_id, $old_qty, 0, $qty,
                        "Purchase Line Deleted:$voucher_num", $upd_inv_id, $invoice_details_id, $login_user, $dtm
                    );
                }
                continue;
            }

            if ($pur_qty <= 0) {
                continue;
            }

            $subTotal = $finalPrice * $pur_qty;

            if ($item_type === 'ROUND_OFF') {
                $cgst = 0.0;
                $sgst = 0.0;
                $igst = 0.0;
                $gst_amt = 0.0;
            } else {
                [$cgst, $sgst, $igst, $gst_amt] = calc_purchase_gst_split($gst_txn_type, $subTotal, $item_gst);
            }

            if ($rec_status === 'upd') {
                $invoice_details_id = (int)($_POST['invoice_details_id'][$i] ?? 0);

                $det_sql = "UPDATE table_invoice_details SET
                                item_id = ?, item_type = ?, item_name = ?, item_note = ?, uom = ?, qty = ?, price = ?,
                                discount_mode = ?, discount_amt = ?, discount_pct = ?, total_amt = ?,
                                hsn_code = ?, gst_pct = ?, CGST = ?, SGST = ?, IGST = ?, gst_amt = ?
                            WHERE invoice_details_id = ? AND parent_invoice_id = ?";
                $stmt = $dbh->prepare($det_sql);
                $stmt->execute([
                    $item_id, $item_type, $item_name, $remark_item, $uom, $pur_qty, $std_price,
                    $discount_mode, $discount_amt, $discount_pct, $subTotal,
                    $hsn_sac, $item_gst, $cgst, $sgst, $igst, $gst_amt,
                    $invoice_details_id, $upd_inv_id
                ]);

                // Purchase update stock logic: reverse old purchase qty, then apply new purchase qty.
                if ($old_is_inventory_item && $old_item_id > 0 && $old_qty > 0 && ($old_item_id !== $item_id || $old_qty != $pur_qty || !$is_inventory_item)) {
                    $qty = $item_obj->reduceItemQty($dbh, $biz_id, $old_item_id, $old_qty);
                    $stk_j->insert_stock_journal(
                        $biz_id, $old_item_id, $old_qty, 0, $qty,
                        "Purchase Update Old Qty:$voucher_num", $upd_inv_id, $invoice_details_id, $login_user, $dtm
                    );
                }

                if ($is_inventory_item && $item_id > 0 && $pur_qty > 0 && ($old_item_id !== $item_id || $old_qty != $pur_qty || !$old_is_inventory_item)) {
                    $qty = $item_obj->addItemQty($dbh, $biz_id, $item_id, $pur_qty);
                    $stk_j->insert_stock_journal(
                        $biz_id, $item_id, 0, $pur_qty, $qty,
                        "Purchase Update New Qty:$voucher_num", $upd_inv_id, $invoice_details_id, $login_user, $dtm
                    );
                }
            }

            if ($rec_status === 'new') {
                $item_srl_no++;
                $det_sql = "INSERT INTO table_invoice_details
                    (biz_id, parent_invoice_id, item_srl_no, item_id, item_type, item_name, item_note, uom, qty, price,
                     discount_mode, discount_amt, discount_pct, total_amt, hsn_code, gst_pct, CGST, SGST, IGST, gst_amt)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $dbh->prepare($det_sql);
                $stmt->execute([
                    $biz_id, $upd_inv_id, $item_srl_no, $item_id, $item_type, $item_name, $remark_item, $uom, $pur_qty, $std_price,
                    $discount_mode, $discount_amt, $discount_pct, $subTotal, $hsn_sac, $item_gst, $cgst, $sgst, $igst, $gst_amt
                ]);

                $invoice_detail_id = (int)$dbh->lastInsertId();

                if ($is_inventory_item && $item_id > 0 && $pur_qty > 0) {
                    $qty = $item_obj->addItemQty($dbh, $biz_id, $item_id, $pur_qty);
                    $stk_j->insert_stock_journal(
                        $biz_id, $item_id, 0, $pur_qty, $qty,
                        "Purchase Item:$voucher_num", $upd_inv_id, $invoice_detail_id, $login_user, $dtm
                    );
                }
            }

            $saved_line_count++;

            if ($item_type === 'ROUND_OFF') {
                $round_off_amt += $subTotal;
            } else {
                $outp += $subTotal;
                $total_cgst += $cgst;
                $total_sgst += $sgst;
                $total_igst += $igst;
                $total_gst_amt += $gst_amt;
            }

            if ($item_type !== 'ROUND_OFF' && $pur_qty > 0 && $finalPrice > 0) {
                $effective_lines++;
            }
        }

        if ($saved_line_count === 0) {
            throw new RuntimeException('Add at least one valid line item.');
        }

        if ($effective_lines === 0) {
            throw new RuntimeException('Add at least one valid purchase item or charge. Round Off alone is not allowed.');
        }

        $save_stage = 3;

        $untaxed  = round((float)$outp, 2);
        $taxCGST  = round((float)$total_cgst, 2);
        $taxSGST  = round((float)$total_sgst, 2);
        $taxIGST  = round((float)$total_igst, 2);
        $taxTotal = round((float)$total_gst_amt, 2);
        $grand    = round($untaxed + $taxTotal + $round_off_amt, 2);

        if ($grand <= 0) {
            throw new RuntimeException('Purchase voucher amount must be greater than zero.');
        }

        $update_sql = "UPDATE table_invoice_header
                          SET total_amt = ?, CGST = ?, SGST = ?, IGST = ?, total_tax = ?, net_amt = ?
                        WHERE invoice_id = ? AND biz_id = ? AND txn_type = ?";
        $stmt = $dbh->prepare($update_sql);
        $stmt->execute([
            $untaxed, $taxCGST, $taxSGST, $taxIGST, $taxTotal, $grand,
            $upd_inv_id, $biz_id, $txn_type
        ]);

        $save_stage = 4;

        $L_PURCHASE = ledger_id_by_name($dbh, $biz_id, 'Purchase Accounts');
        $L_CGST  = ($taxCGST > 0) ? ledger_id_by_name($dbh, $biz_id, 'Input CGST') : null;
        $L_SGST  = ($taxSGST > 0) ? ledger_id_by_name($dbh, $biz_id, 'Input SGST') : null;
        $L_IGST  = ($taxIGST > 0) ? ledger_id_by_name($dbh, $biz_id, 'Input IGST') : null;
        $L_ROUND = ($round_off_amt != 0.0) ? ledger_id_by_name($dbh, $biz_id, 'Rounding Difference') : null;
        $L_AP = $vendor_id;

        if ($L_AP <= 0) {
            throw new RuntimeException('Invalid vendor ledger.');
        }

        $lines = [];
        if ($untaxed != 0.0) $lines[] = ['ledger_id' => $L_PURCHASE, 'debit' => $untaxed];
        if ($L_CGST && $taxCGST != 0.0) $lines[] = ['ledger_id' => $L_CGST, 'debit' => $taxCGST];
        if ($L_SGST && $taxSGST != 0.0) $lines[] = ['ledger_id' => $L_SGST, 'debit' => $taxSGST];
        if ($L_IGST && $taxIGST != 0.0) $lines[] = ['ledger_id' => $L_IGST, 'debit' => $taxIGST];

        // Purchase round-off direction: positive increases payable, negative reduces payable.
        if ($L_ROUND && $round_off_amt > 0) {
            $lines[] = ['ledger_id' => $L_ROUND, 'debit' => round($round_off_amt, 2)];
        } elseif ($L_ROUND && $round_off_amt < 0) {
            $lines[] = ['ledger_id' => $L_ROUND, 'credit' => abs(round($round_off_amt, 2))];
        }

        if ($grand != 0.0) $lines[] = ['ledger_id' => $L_AP, 'credit' => $grand];

        $sumDr = 0.0;
        $sumCr = 0.0;
        foreach ($lines as $ln) {
            if (isset($ln['debit']))  $sumDr += (float)$ln['debit'];
            if (isset($ln['credit'])) $sumCr += (float)$ln['credit'];
        }
        $sumDr = round($sumDr, 2);
        $sumCr = round($sumCr, 2);
        if ($sumDr !== $sumCr) {
            throw new RuntimeException("Unbalanced journal (Dr {$sumDr} != Cr {$sumCr}).");
        }

        $lj = new Ledger_Journal($dbh);
        $lj->UpdateByRef(
            biz_id:       $biz_id,
            jrnl_date:    $voucher_date,
            src_txn_type: 'PURCHASE',
            src_txn_id:   (int)$upd_inv_id,
            src_txn_num:  null,
            created_by:   $login_user,
            new_lines:    $lines
        );

        $save_stage = 5;

        $dbh->commit();
        header("Location: ".$src_loc);
        exit;

    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        error_log("PURCHASE-UPDATE: {$biz_id}:{$upd_inv_id}:".$e->getMessage());
        echo "<div class='alert alert-danger'>Error In Updating Purchase Voucher:{$save_stage} ".h($e->getMessage())."</div>";
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <title>Purchase Voucher Update</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=yes">

  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" type="text/css" rel="stylesheet">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

  <style>
    body { background-color:#ccf2ff; }
    .page-actions { position: sticky; top: 0; z-index: 1000; background: #ccf2ff; padding: 8px 0; }
    .fld8 { width: 8ch; max-width: 8ch; }
    .fld12 { width: 12ch; max-width: 12ch; }
    .totbox { font-weight: bold; }
    .totrow td { background:#f5f5f5; }
    td.disc-mode { min-width: 90px; }
    td.disc-val  { min-width: 90px; }
    td.disc-mode select { height: 30px; padding: 4px 6px; }
    .supplier-panel .form-group { margin-bottom: 6px; }
    .supplier-panel .control-label { text-align: left; padding-left: 6px; padding-top: 4px; white-space: nowrap; }
    .supplier-panel .control-label.small { width: 40px; min-width: 40px; max-width: 40px; }
    .supplier-panel .form-control { height: 30px; padding: 4px 6px; width: 100%; }
    #ItemDetailsPanel .form-control { height: 28px; padding: 3px 6px; font-size: 12px; }
    #ItemDetailsPanel textarea { resize: vertical; height:auto; }
    #ItemDetailsPanel th { white-space: nowrap; font-size: 12px; }
    #ItemDetailsPanel td { vertical-align: middle; }
  </style>

  <script>
  function searchName(){
    var biz_id = $('#biz_id').val();
    var vendor_name = $('#srch_cust_name').val();
    $.post('party-search-name-ajax.php',
      {p_act_grp:'vendor', p_biz_id:biz_id, p_cust_name:vendor_name},
      function(response){ $('#searchOutput').html(response).show(); });
  }

  function searchPhone(){
    var biz_id = $('#biz_id').val();
    var phone = $('#srch_cust_number').val();
    $.post('party-search-contact-ajax.php',
      {p_act_grp:'vendor', p_biz_id:biz_id, p_cust_number:phone},
      function(response){ $('#searchOutput').html(response).show(); });
  }

  function searchEmail(){
    var biz_id = $('#biz_id').val();
    var email = $('#srch_cust_email').val();
    $.post('party-search-email-ajax.php',
      {p_act_grp:'vendor', p_biz_id:biz_id, p_cust_email:email},
      function(response){ $('#searchOutput').html(response).show(); });
  }

  function set_party(val){
    var str_array = String(val || '').split(':');
    $.ajax({
      type: 'post',
      url: 'party-info-fetch-ajax.php',
      data: { cust_id: str_array[0] },
      success: function(response){
        var obj = JSON.parse(response || '{}');
        $('#vendor_id').val(obj.account_id || '');
        $('#vendor_name').val(obj.account_name || '');
        $('#vendor_name_dup').val(obj.account_name || '');
        $('#vendor_address').val(obj.address || '');
        $('#vendor_phone').val(obj.phone_num || '');
        $('#vendor_state').val(obj.state || '');
        $('#vendor_pincode').val(obj.pincode || '');
        $('#vendor_gstin').val(obj.gstin || '');
        recalcAllTotalsPurchase();
        updateRoundOffIfPresentPurchase();
        $('#sup_invoice_num').focus();
      }
    });
  }

  function toggleSupplier(cb){
    $('#SupplierDetails').toggle(cb.checked);
  }
  </script>
</head>

<body>
<div class="container-fluid">
  <div><?php include 'header.inc.php'; ?></div>
  <center><h3 class="text-primary" style="margin-top:50px;">Purchase Voucher Update</h3></center>

  <div class="row page-actions">
    <div class="col-sm-3">
      <a href="<?php echo h($src_loc); ?>" style="border-radius:0">❮ Back</a>
    </div>
    <div class="col-sm-9 text-right">
      <form id="deleteForm" method="post">
        <input type="hidden" id="biz_id_del" name="biz_id" value="<?php echo (int)$biz_id; ?>">
        <input type="hidden" name="update_id" value="<?php echo (int)$upd_inv_id; ?>">
        <input type="hidden" name="src_loc" value="<?php echo h($src_loc); ?>">
        <button type="button" class="btn btn-danger" onclick="$('#confirmDeleteModal').modal('show')">
          <span class="glyphicon glyphicon-trash" aria-hidden="true"></span> Delete
        </button>
      </form>
    </div>
  </div>
</div>

<main>
  <div class="container container-md mt-6 p-4">
    <form id="purchaseForm" method="POST">
		<input type="hidden" name="save_purchase_update" value="1">
		<input type="hidden" id="biz_id" name="biz_id" value="<?php echo (int)$biz_id; ?>">
		<input type="hidden" id="update_id" name="update_id" value="<?php echo (int)$upd_inv_id; ?>">
		<input type="hidden" name="src_loc" value="<?php echo h($src_loc); ?>">

      <div class="form-group row">
        <label class="control-label col-md-2" for="voucher_num">Purchase Voucher No<span style="color:red">*</span></label>
        <div class="col-md-3">
          <input name="voucher_num" id="voucher_num" required class="input-md" type="text" value="<?php echo h($head_row['invoice_num']); ?>">
        </div>
        <label class="control-label col-md-2" for="voucher_date">Entry Date<span style="color:red">*</span></label>
        <div class="col-md-3">
          <input name="voucher_date" id="voucher_date" required class="input-md" type="date" value="<?php echo h($head_row['invoice_dt']); ?>">
        </div>
      </div>

      <div class="row" style="margin-bottom:2px;margin-top:10px;">
        <label class="control-label col-md-2" for="sup_invoice_num">Supplier Invoice Number</label>
        <div class="col-md-2">
          <input name="sup_invoice_num" id="sup_invoice_num" class="input-md" type="text" value="<?php echo h($head_row['ref_doc_no']); ?>">
        </div>
        <div class="col-md-1"></div>
        <label class="control-label col-md-2" for="sup_invoice_date">Supplier Invoice Date</label>
        <div class="col-md-3">
          <input type="date" name="sup_invoice_date" id="sup_invoice_date" class="input-md" value="<?php echo h($head_row['ref_doc_date']); ?>">
        </div>
      </div>

      <div class="panel panel-default" id="SupplierPanel" style="margin-top:10px;">
        <div class="panel-heading" style="display:flex; align-items:center; gap:10px;">
          <strong style="min-width:140px;">Supplier Details</strong>
          <button type="button" class="btn btn-info btn-xs" data-toggle="modal" data-target="#PartyModal">Select Supplier</button>
          <span><b>ID:</b> <input readonly id="vendor_id" name="vendor_id" style="width:50px; height:22px;" value="<?php echo h($head_row['invoice_cust_id']); ?>"></span>
          <span><b>Name:</b> <input readonly id="vendor_name" name="vendor_name" style="width:220px; height:22px;" value="<?php echo h($head_row['cust_name']); ?>"></span>
          <span style="margin-left:auto;">
            <label style="font-weight:normal; margin:0;">
              <input type="checkbox" checked onchange="toggleSupplier(this)"> Show/Hide Supplier Details
            </label>
          </span>
        </div>

        <div class="panel-body supplier-panel" id="SupplierDetails" style="padding:10px;">
          <div style="border:1px solid #ddd; padding:12px; border-radius:4px; background:#fafafa;">
            <div class="row form-group">
              <label class="col-md-2 control-label">Name</label>
              <div class="col-md-10"><input readonly class="form-control" id="vendor_name_dup" name="vendor_name_dup" value="<?php echo h($head_row['cust_name']); ?>"></div>
            </div>
            <div class="row form-group">
              <label class="col-md-2 control-label">Address</label>
              <div class="col-md-10"><textarea readonly class="form-control" rows="2" id="vendor_address" name="vendor_address"><?php echo h($head_row['bill_to_address']); ?></textarea></div>
            </div>
            <div class="row form-group">
              <label class="col-md-2 control-label">State</label>
              <div class="col-md-5"><input readonly class="form-control" id="vendor_state" name="vendor_state" value="<?php echo h($head_row['bill_to_state']); ?>"></div>
              <label class="col-md-2 control-label small">PIN</label>
              <div class="col-md-4"><input readonly class="form-control" id="vendor_pincode" name="vendor_pincode" value="<?php echo h($head_row['bill_to_pincode']); ?>"></div>
            </div>
            <div class="row form-group">
              <label class="col-md-2 control-label">GSTIN</label>
              <div class="col-md-5"><input readonly class="form-control" id="vendor_gstin" name="vendor_gstin" value="<?php echo h($head_row['bill_to_gstin']); ?>"></div>
              <label class="col-md-2 control-label small">Phone</label>
              <div class="col-md-4"><input readonly class="form-control" id="vendor_phone" name="vendor_phone" value="<?php echo h($head_row['bill_to_phone']); ?>"></div>
            </div>
          </div>
        </div>
      </div>

      <div class="row" style="margin-bottom:2px;margin-top:10px;<?php if ($allow_remark_txn == 'N') echo 'display:none;'; ?>">
        <label class="control-label col-md-2" for="remark_txn">Remark</label>
        <div class="col-md-10">
          <input name="remark_txn" id="remark_txn" class="form-control" type="text" value="<?php echo h($head_row['note'] ?? ''); ?>">
        </div>
      </div>

      <div class="panel panel-default" id="ItemDetailsPanel" style="margin-top:12px;">
        <div class="panel-heading" style="display:flex; align-items:center; justify-content:space-between; padding:8px 12px;">
          <strong>Item Details</strong>
          <div>
            <button type="button" class="btn btn-warning btn-xs" id="btnAddRoundOff">Add Round Off</button>
            <button type="button" class="btn btn-primary btn-xs" id="btnOpenItemModal" data-toggle="modal" data-target="#ItemModal" style="margin-left:6px;">Add Item</button>
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
                $discount_options = ['NONE' => 'NONE', 'AMT' => 'AMT', 'PCT' => 'PCT'];
                foreach ($det_rows as $det_row) {
                    $item_type = strtoupper((string)($det_row['item_type'] ?? ''));
                    $stdPrice = (float)($det_row['price'] ?? 0);
                    $discount_mode = strtoupper((string)($det_row['discount_mode'] ?? 'NONE'));

                    if ($discount_mode === 'AMT') {
                        $discAmt = (float)($det_row['discount_amt'] ?? 0);
                        $finalPrice = $stdPrice - $discAmt;
                    } elseif ($discount_mode === 'PCT') {
                        $discAmt = (float)($det_row['discount_pct'] ?? 0);
                        $finalPrice = $stdPrice - (($stdPrice * $discAmt) / 100.0);
                    } else {
                        $discAmt = 0.0;
                        $discount_mode = 'NONE';
                        $finalPrice = $stdPrice;
                    }

                    if ($item_type !== 'ROUND_OFF' && $finalPrice < 0) {
                        $finalPrice = 0;
                    }

                    $qty = ($item_type === 'CHARGE' || $item_type === 'ROUND_OFF') ? 1 : (float)($det_row['qty'] ?? 0);
                    $gst_pct = ($item_type === 'ROUND_OFF') ? 0.0 : (float)($det_row['gst_pct'] ?? 0);
                    $subTotal = $finalPrice * $qty;
                    $itemTotal = $subTotal + (($item_type === 'ROUND_OFF') ? 0 : ($subTotal * $gst_pct / 100.0));
                    $ro = ($item_type === 'CHARGE' || $item_type === 'ROUND_OFF') ? 'readonly' : '';

                    echo "<tr id='prodRow_{$t}'>";
                    echo "<td>";
                    echo "<input id='rec_status_{$t}' name='rec_status[]' value='upd' type='hidden'>";
                    echo "<input id='item_type_{$t}' name='item_type[]' value='".h($item_type)."' type='hidden'>";
                    echo "<input id='old_item_type_{$t}' name='old_item_type[]' value='".h($item_type)."' type='hidden'>";
                    echo "<input id='item_id_{$t}' name='item_id[]' value='".(int)$det_row['item_id']."' type='hidden'>";
                    echo "<input id='old_item_id_{$t}' name='old_item_id[]' value='".(int)$det_row['item_id']."' type='hidden'>";
                    echo "<input id='invoice_details_id_{$t}' name='invoice_details_id[]' value='".(int)$det_row['invoice_details_id']."' type='hidden'>";
                    echo "<input readonly id='item_name_{$t}' class='form-control form-control-lg' name='item_name[]' value='".h($det_row['item_name'] ?? '')."'>";
                    echo "<textarea id='remark_item_{$t}' class='form-control form-control-lg' ".($allow_remark_item == 'N' ? "style=\"display:none\"" : "")." name='remark_item[]'>".h($det_row['item_note'] ?? '')."</textarea>";
                    echo "</td>";

                    echo "<td><input id='hsn_sac_{$t}' class='form-control form-control-lg fld8' name='hsn_sac[]' value='".h($det_row['hsn_code'] ?? '')."'></td>";
                    echo "<td><input readonly id='uom_{$t}' class='form-control form-control-lg fld8' name='uom[]' value='".h($det_row['uom'] ?? '')."'></td>";
                    echo "<td><input id='item_price_{$t}' class='form-control form-control-lg fld12' onchange='showTotalPurchaseSafe({$t})' name='item_price[]' type='number' step='0.01' value='".h($det_row['price'] ?? 0)."'></td>";

                    echo "<td><select id='discMode_{$t}' class='form-control form-control-lg' name='discMode[]' style='width:80px;' onchange='showTotalPurchaseSafe({$t})'>";
                    foreach ($discount_options as $key => $value) {
                        $selected = ($discount_mode === $key) ? 'selected' : '';
                        echo "<option {$selected} value='".h($key)."'>".h($value)."</option>";
                    }
                    echo "</select></td>";

                    echo "<td><input id='discAmt_{$t}' class='form-control form-control-lg fld8' onchange='showTotalPurchaseSafe({$t})' name='discAmt[]' type='number' step='0.01' value='".h($discAmt)."'></td>";
                    echo "<td>";
                    echo "<input id='old_qty_{$t}' type='hidden' name='old_qty[]' value='".h($det_row['qty'] ?? 0)."'>";
                    echo "<input id='quantity_{$t}' class='form-control form-control-lg fld12' onchange='showTotalPurchaseSafe({$t})' {$ro} name='quantity[]' type='number' step='0.001' value='".h($qty)."'>";
                    echo "</td>";
                    echo "<td><div id='itemSubTotal_{$t}'>".round($subTotal, 2)."</div></td>";
                    echo "<td><input id='itemGST_{$t}' class='form-control form-control-lg fld8' name='itemGST[]' type='number' step='0.01' value='".h($gst_pct)."' onchange='showTotalPurchaseSafe({$t})'></td>";
                    echo "<td><div id='itemTotal_{$t}'>".round($itemTotal, 2)."</div></td>";
                    echo "<td><button type='button' id='remove_{$t}' class='btn btn-danger btn-xs' onclick='removePurchase({$t})'>X</button></td>";
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
          <button name="submit" class="btn btn-success" type="submit" value="submit">Save Voucher</button>
        </div>
      </div>
    </form>
  </div>
</main>

<!-- Supplier Modal -->
<div class="modal fade" id="PartyModal" role="dialog" style="z-index:10000;">
  <div class="modal-dialog modal-md">
    <div class="modal-content">
      <div class="modal-header" style="background:#ed7c65;">
        <button type="button" class="close" data-dismiss="modal" style="color:#fff;">&times;</button>
        <h4 class="modal-title" style="color:#FFFFFF;">Select Supplier</h4>
      </div>
      <div class="modal-body" style="height:380px;">
        <div class="container-fluid">
          <ul class="nav nav-tabs nav-justified" id="mytab">
            <li class="active"><a data-toggle="tab" href="#log">Search</a></li>
          </ul>
          <div class="tab-content" style="margin-top:3px;">
            <div id="log" class="tab-pane fade in active">
              <div class="row"><div class="col-md-2"><b>Name:</b></div><div class="col-md-8"><input id="srch_cust_name" placeholder="Name" type="text"><button type="button" onclick="searchName()">Search</button></div></div>
              <div class="row"><div class="col-md-2"><b>Contact:</b></div><div class="col-md-8"><input id="srch_cust_number" placeholder="Phone Number" type="text"><button type="button" onclick="searchPhone()">Search</button></div></div>
              <div class="row"><div class="col-md-2"><b>Email:</b></div><div class="col-md-8"><input id="srch_cust_email" placeholder="Email" type="text"><button type="button" onclick="searchEmail()">Search</button></div></div>
              <hr>
              <div id="searchOutput" style="display:none; border:1px solid #ccc;"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Delete confirmation modal -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header" style="background:#d9534f;color:#fff;">
        <button type="button" class="close" data-dismiss="modal">×</button>
        <h4 class="modal-title">Delete this purchase voucher?</h4>
      </div>
      <div class="modal-body">
        <p>This will reverse stock and ledger entries.</p>
        <p class="text-danger"><small>This action cannot be undone.</small></p>
        <p>Type <b><?php echo h($head_row['invoice_num']); ?></b> to confirm:</p>
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
  var must = <?php echo json_encode((string)$head_row['invoice_num']); ?>;
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

<!-- Item Modal -->
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
            <input type="text" id="itemSearchQuery" class="form-control" placeholder="Type item name/code..." style="min-width:280px;">
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

<script>
var purchaseRowCounter = <?php echo $det_num_rows + 1; ?>;
var purchaseItemCache = Object.create(null);
var allowRemarkItem = <?php echo json_encode((string)$allow_remark_item); ?>;
var updatingRoundOffPurchase = false;
var COMP_STATE = <?php echo json_encode((string)($comp_state ?? '')); ?>;

function pMoney2(x){
  var v = parseFloat(x);
  if (isNaN(v)) v = 0;
  return (Math.round(v * 100) / 100).toFixed(2);
}

function norm(s){ return String(s || '').trim().toLowerCase(); }

function isLocalPurchaseTxn(){
  var vs = norm($('#vendor_state').val());
  var cs = norm(COMP_STATE);
  if (!vs || !cs) return true;
  return vs === cs;
}

function removeRowPurchase(t){
  var el = document.getElementById('prodRow_' + t);
  if (el) el.remove();
  recalcAllTotalsPurchase();
  updateRoundOffIfPresentPurchase();
}

function removePurchase(t){
  var row = document.getElementById('prodRow_' + t);
  var rec_status_el = document.getElementById('rec_status_' + t);
  var btn = document.getElementById('remove_' + t);

  if (!row || !rec_status_el) return;

  if (rec_status_el.value === 'new') {
    row.remove();
  } else if (rec_status_el.value === 'upd') {
    rec_status_el.value = 'del';
    row.style.backgroundColor = 'red';
    if (btn) btn.innerHTML = 'UnDelete';
  } else if (rec_status_el.value === 'del') {
    rec_status_el.value = 'upd';
    row.style.backgroundColor = 'white';
    if (btn) btn.innerHTML = 'X';
  }

  recalcAllTotalsPurchase();
  updateRoundOffIfPresentPurchase();
}

function showTotalPurchaseSafe(t){
  var itemType = String($('#item_type_' + t).val() || '').toUpperCase();
  var qty = parseFloat($('#quantity_' + t).val() || '0');
  var rate = parseFloat($('#item_price_' + t).val() || '0');
  var gstp = parseFloat($('#itemGST_' + t).val() || '0');
  var mode = String($('#discMode_' + t).val() || 'NONE').toUpperCase();
  var dval = parseFloat($('#discAmt_' + t).val() || '0');

  if (isNaN(qty) || qty < 0) qty = 0;
  if (isNaN(rate)) rate = 0;
  if (itemType !== 'ROUND_OFF' && rate < 0) rate = 0;
  if (isNaN(gstp) || gstp < 0) gstp = 0;
  if (isNaN(dval) || dval < 0) dval = 0;

  if (itemType === 'ROUND_OFF') {
    qty = 1;
    gstp = 0;
    mode = 'NONE';
    dval = 0;
    $('#quantity_' + t).val('1').prop('readonly', true);
    $('#itemGST_' + t).val('0').prop('readonly', true);
    $('#discMode_' + t).val('NONE').css({'pointer-events':'none','background':'#eee'});
    $('#discAmt_' + t).val('0').prop('readonly', true);
    $('#item_price_' + t).attr('min', '-999999');
  }

  if (itemType === 'CHARGE') {
    $('#quantity_' + t).val('1').prop('readonly', true);
    $('#discMode_' + t).val('NONE').css({'pointer-events':'none','background':'#eee'});
    $('#discAmt_' + t).val('0').prop('readonly', true);
  }

  var disc = 0;
  if (mode === 'AMT') disc = dval;
  else if (mode === 'PCT') disc = rate * (dval / 100.0);

  if (disc < 0) disc = 0;
  if (rate > 0 && disc > rate) disc = rate;

  var finalRate = rate - disc;
  var subTotal = qty * finalRate;
  var tax = (itemType === 'ROUND_OFF') ? 0 : subTotal * (gstp / 100.0);
  var lineTotal = subTotal + tax;

  $('#itemSubTotal_' + t).text(pMoney2(subTotal));
  $('#itemTotal_' + t).text(pMoney2(lineTotal));

  recalcAllTotalsPurchase();
  updateRoundOffIfPresentPurchase();
}

function recalcAllTotalsPurchase(){
  var totTaxable = 0, totGst = 0, totNet = 0, totC = 0, totS = 0, totI = 0;

  $('#js1 tr[id^="prodRow_"]').each(function(){
    var t = this.id.split('_')[1];
    var recStatus = String($('#rec_status_' + t).val() || '').toLowerCase();
    if (recStatus === 'del') return;

    var itemType = String($('#item_type_' + t).val() || '').toUpperCase();
    var sub = parseFloat($('#itemSubTotal_' + t).text() || '0');
    var net = parseFloat($('#itemTotal_' + t).text() || '0');

    if (isNaN(sub)) sub = 0;
    if (isNaN(net)) net = 0;

    if (itemType === 'ROUND_OFF') {
      totNet += net;
      return;
    }

    var gst = net - sub;
    if (gst < 0) gst = 0;

    totTaxable += sub;
    totGst += gst;
    totNet += net;

    if (isLocalPurchaseTxn()) { totC += gst / 2.0; totS += gst / 2.0; }
    else { totI += gst; }
  });

  $('#tot_taxable').text(pMoney2(totTaxable));
  $('#tot_gst').text(pMoney2(totGst));
  $('#tot_net').text(pMoney2(totNet));
  $('#tot_cgst').text(pMoney2(totC));
  $('#tot_sgst').text(pMoney2(totS));
  $('#tot_igst').text(pMoney2(totI));
}

function addPurchaseItemRow(it){
  purchaseRowCounter++;
  var t = purchaseRowCounter;

  var itemId = (it.item_id ?? '');
  var itemType = String(it.item_type || 'ITEM').toUpperCase();
  var name = it.item_name || it.item_disp_name || '';
  var uom = it.item_uom || '';
  var hsn = it.hsn_code || '';
  var price = (it.item_pur_price ?? it.item_purchase_price ?? it.purchase_price ?? it.item_std_price ?? it.item_mrp ?? 0);
  var gst = (it.gst ?? 0);

  var itemIdStr = String(itemId ?? '').trim();
  if (itemIdStr !== '' && itemIdStr !== '0') {
    var exists = $('#js1 input[name="item_id[]"]').filter(function(){ return this.value == itemId; }).length;
    if (exists) { alert('Item already added. Change qty in existing line.'); return; }
  }

  var $tr = $('<tr/>', { id: 'prodRow_' + t });

  var $nameTd = $('<td/>');
  $nameTd.append(
    $('<input/>', { type:'hidden', name:'rec_status[]', id:'rec_status_' + t, value:'new' }),
    $('<input/>', { type:'hidden', name:'item_id[]', id:'item_id_' + t, value:itemId }),
    $('<input/>', { type:'hidden', name:'old_item_id[]', id:'old_item_id_' + t, value:itemId }),
    $('<input/>', { type:'hidden', name:'item_type[]', id:'item_type_' + t, value:itemType }),
    $('<input/>', { type:'hidden', name:'old_item_type[]', id:'old_item_type_' + t, value:itemType }),
    $('<input/>', { type:'hidden', name:'invoice_details_id[]', id:'invoice_details_id_' + t, value:'0' }),
    $('<input/>', { type:'hidden', name:'old_qty[]', id:'old_qty_' + t, value:'0' }),
    $('<input/>', { type:'text', class:'input-md', readonly:true, name:'item_name[]', id:'item_name_' + t, value:name })
  );

  var $remark = $('<textarea/>', {
    class: 'form-control input-sm',
    name: 'remark_item[]',
    id: 'remark_item_' + t,
    placeholder: 'Item remark',
    rows: 2,
    style: 'min-width:180px; resize:vertical;'
  });
  if (String(allowRemarkItem).toUpperCase() === 'N') $remark.css('display','none');
  $nameTd.append('<br>', $remark);
  $tr.append($nameTd);

  $tr.append($('<td/>').append($('<input/>', { type:'text', class:'input-md fld8', readonly:true, name:'hsn_sac[]', id:'hsn_sac_' + t, value:hsn })));
  $tr.append($('<td/>').append($('<input/>', { type:'text', class:'input-md fld8', readonly:true, name:'uom[]', id:'uom_' + t, value:uom })));
  $tr.append($('<td/>').append($('<input/>', { type:'number', step:'0.01', min:'0', class:'input-md fld12', name:'item_price[]', id:'item_price_' + t, value:price })));

  $tr.append($('<td/>').append(
    $('<select/>', { class:'disc-mode', name:'discMode[]', id:'discMode_' + t })
      .append('<option value="NONE">NONE</option>')
      .append('<option value="AMT">AMT</option>')
      .append('<option value="PCT">PCT</option>')
  ));

  $tr.append($('<td/>').append($('<input/>', { type:'number', step:'0.01', min:'0', class:'disc-val fld8', name:'discAmt[]', id:'discAmt_' + t, value:'0' })));
  $tr.append($('<td/>').append($('<input/>', { type:'number', step:'0.001', min:'0', class:'input-md fld12', name:'quantity[]', id:'quantity_' + t, value:'1' })));
  $tr.append($('<td/>').append($('<span/>', { id:'itemSubTotal_' + t }).text('0.00')));
  $tr.append($('<td/>').append($('<input/>', { type:'number', step:'0.01', min:'0', class:'input-md fld8', name:'itemGST[]', id:'itemGST_' + t, value:gst })));
  $tr.append($('<td/>').append($('<span/>', { id:'itemTotal_' + t }).text('0.00')));
  $tr.append($('<td/>').append($('<button/>', { type:'button', class:'btn btn-danger btn-xs' }).text('X').on('click', function(){ removeRowPurchase(t); })));

  $('#js1').append($tr);

  if (itemType === 'CHARGE') {
    $('#quantity_' + t).val('1').prop('readonly', true);
    $('#discMode_' + t).val('NONE').css({'pointer-events':'none','background':'#eee'});
    $('#discAmt_' + t).val('0').prop('readonly', true);
  }

  if (itemType === 'ROUND_OFF') {
    $('#quantity_' + t).val('1').prop('readonly', true);
    $('#itemGST_' + t).val('0').prop('readonly', true);
    $('#discMode_' + t).val('NONE').css({'pointer-events':'none','background':'#eee'});
    $('#discAmt_' + t).val('0').prop('readonly', true);
    $('#item_price_' + t).attr('min', '-999999');
  }

  $('#quantity_' + t + ', #item_price_' + t + ', #discMode_' + t + ', #discAmt_' + t + ', #itemGST_' + t)
    .on('input change', function(){ showTotalPurchaseSafe(t); });

  showTotalPurchaseSafe(t);
}

function findRoundOffTPurchase(){
  var found = null;
  $('#js1 tr[id^="prodRow_"]').each(function(){
    var t = this.id.split('_')[1];
    var recStatus = String($('#rec_status_' + t).val() || '').toLowerCase();
    if (recStatus === 'del') return;
    var typ = String($('#item_type_' + t).val() || '').toUpperCase();
    if (typ === 'ROUND_OFF') { found = t; return false; }
  });
  return found;
}

function sumNetExcludingRoundOffPurchase(){
  var sum = 0;
  $('#js1 tr[id^="prodRow_"]').each(function(){
    var t = this.id.split('_')[1];
    var recStatus = String($('#rec_status_' + t).val() || '').toLowerCase();
    if (recStatus === 'del') return;
    var typ = String($('#item_type_' + t).val() || '').toUpperCase();
    if (typ === 'ROUND_OFF') return;
    var net = parseFloat($('#itemTotal_' + t).text() || '0');
    if (!isNaN(net)) sum += net;
  });
  return sum;
}

function updateRoundOffIfPresentPurchase(){
  if (updatingRoundOffPurchase) return;
  var rt = findRoundOffTPurchase();
  if (!rt) return;

  updatingRoundOffPurchase = true;
  try {
    var baseNet = sumNetExcludingRoundOffPurchase();
    var rounded = Math.round(baseNet);
    var diff = +(rounded - baseNet).toFixed(2);

    $('#quantity_' + rt).val('1').prop('readonly', true);
    $('#itemGST_' + rt).val('0').prop('readonly', true);
    $('#discMode_' + rt).val('NONE').css({'pointer-events':'none','background':'#eee'});
    $('#discAmt_' + rt).val('0').prop('readonly', true);
    $('#item_price_' + rt).attr('min', '-999999').val(diff.toFixed(2));

    showTotalPurchaseSafe(rt);
  } finally {
    updatingRoundOffPurchase = false;
  }
}

function addRoundOffRowPurchase(){
  var existingT = findRoundOffTPurchase();
  if (existingT) { updateRoundOffIfPresentPurchase(); return; }

  var it = {
    item_id: 0,
    item_name: 'Round Off',
    item_uom: 'NOS',
    hsn_code: '',
    item_pur_price: 0,
    gst: 0,
    item_type: 'ROUND_OFF'
  };
  addPurchaseItemRow(it);
  updateRoundOffIfPresentPurchase();
}

(function(){
  $('#ItemModal').on('shown.bs.modal', function(){
    $('#itemSearchQuery').val('').focus();
    $('#itemSearchResults').empty();
    $('#btnAddSelectedItem').prop('disabled', true);
    $('#itemResultHelp').hide().text('');
    purchaseItemCache = Object.create(null);
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
    purchaseItemCache = Object.create(null);

    $.ajax({
      url: 'dc-item-searched-list-ajax.php',
      method: 'POST',
      dataType: 'json',
      data: { biz_id: biz_id, q: q }
    }).done(function(resp){
      if (!resp || !resp.ok) { $help.text((resp && resp.msg) ? resp.msg : 'Search failed.').show(); return; }
      if (!resp.items || !resp.items.length) { $help.text('No items found for "' + q + '".').show(); return; }

      resp.items.forEach(function(it){
        var itemId = String(it.item_id || '');
        if (!itemId) return;
        purchaseItemCache[itemId] = it;
        var text = '[' + itemId + '] ' + (it.item_name || it.item_disp_name || '') + (it.item_uom ? (' (' + it.item_uom + ')') : '');
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
    var it = purchaseItemCache[itemId];
    if (!it) return;
    addPurchaseItemRow(it);
    $('#ItemModal').modal('hide');
  });

  $('#itemSearchQuery').on('keydown', function(e){
    if (e.keyCode === 13) { e.preventDefault(); $('#btnItemSearch').click(); }
  });
  $('#itemSearchResults').on('dblclick', function(){ $('#btnAddSelectedItem').click(); });
})();

$(function(){
  $('#btnAddRoundOff').on('click', function(){ addRoundOffRowPurchase(); });

  $('#sup_invoice_num').on('input change', function(){
    var hasVal = $.trim($(this).val()) !== '';
    var $dt = $('#sup_invoice_date');
    if (hasVal) {
      $dt.prop('disabled', false);
      if ($dt.val() === '') {
        var today = new Date().toISOString().slice(0,10);
        $dt.val(today);
      }
    } else {
      $dt.val('');
      $dt.prop('disabled', true);
    }
  }).trigger('change');

  $('#js1 tr[id^="prodRow_"]').each(function(){
    var t = this.id.split('_')[1];
    showTotalPurchaseSafe(t);
  });

  recalcAllTotalsPurchase();
  updateRoundOffIfPresentPurchase();

  $('#purchaseForm').on('submit', function(e){
    var $form = $(this);

    if ($form.data('saving') === true) {
      e.preventDefault();
      return false;
    }

    if ($.trim($('#vendor_id').val() || '') === '') {
      alert('Select a supplier first.');
      $('#vendor_id').focus();
      e.preventDefault();
      return false;
    }

    var hasAnyLine = false;
    var hasValidLine = false;

    $('#js1 tr[id^="prodRow_"]').each(function(){
      var t = this.id.split('_')[1];
      var recStatus = String($('#rec_status_' + t).val() || '').toLowerCase();
      if (recStatus === 'del') return;

      hasAnyLine = true;

      var itemType = String($('#item_type_' + t).val() || '').toUpperCase();
      var qty = parseFloat($('#quantity_' + t).val() || '0');
      var price = parseFloat($('#item_price_' + t).val() || '0');

      if (isNaN(qty)) qty = 0;
      if (isNaN(price)) price = 0;

      if (itemType !== 'ROUND_OFF' && qty > 0 && price > 0) {
        hasValidLine = true;
        return false;
      }
    });

    if (!hasAnyLine) {
      alert('Add at least one item.');
      e.preventDefault();
      return false;
    }

    if (!hasValidLine) {
      alert('Add at least one valid purchase item or charge. Round Off alone is not allowed.');
      e.preventDefault();
      return false;
    }

    if (!confirm('Proceed to save purchase voucher?')) {
      e.preventDefault();
      return false;
    }

    $form.data('saving', true);
    $('#purchaseForm button[type="submit"]').prop('disabled', true).text('Saving...');
    return true;
  });
});
</script>
</body>
</html>
