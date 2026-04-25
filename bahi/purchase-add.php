<?php
ob_start();
session_start();

require_once 'include/session.php';
require_once 'include/dbo.php';
require_once 'include/param.php';

require_once 'include/item.php';
require_once 'include/stock_journal.php';
require_once 'include/ledger_journal.php';

checksession();

$debug      = 0;
$txn_type   = 'PURCHASE';
$doc_type   = 'PURCHASE';
$purchase_list_url = 'purchase-manage.php';

$login_user = $_SESSION['pos_login'] ?? 'system';
$biz_id     = (int)($_SESSION['biz_id'] ?? 0);
if ($biz_id <= 0) { die('Invalid session (biz).'); }

require_once 'company-info.php';

// Optional document configuration. If config is missing/does not define these, keep remarks enabled.
$allow_remark_txn  = 'Y';
$allow_remark_item = 'Y';
if (file_exists('config-doc-entry-info.php')) {
    include 'config-doc-entry-info.php';
    $allow_remark_txn  = $allow_remark_txn  ?? 'Y';
    $allow_remark_item = $allow_remark_item ?? 'Y';
}

$dbh   = new dbo();
$item  = new Item();
$stk_j = new Stock_Journal($dbh);
$lj    = new Ledger_Journal($dbh);
$dtm   = getLocalDtm();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function nextDocNumber(PDO $dbh, int $biz_id, string $txn_type): string {
    $doc_prefix = 'PUR-';
    $len_sno    = 3;
    $sno_start  = 1;
    $sno_pad    = '0';

    $stc = $dbh->prepare("SELECT doc_prefix, sno_len, sno_start, sno_pad
                            FROM config_doc_prefix
                           WHERE biz_id = :biz AND doc_type = :dt
                           LIMIT 1");
    $stc->execute([':biz' => $biz_id, ':dt' => $txn_type]);
    if ($row = $stc->fetch(PDO::FETCH_ASSOC)) {
        $doc_prefix = (string)$row['doc_prefix'];
        $len_sno    = (int)$row['sno_len'];
        $sno_start  = (int)$row['sno_start'];
        $sno_pad    = (string)$row['sno_pad'];
    }

    $prefixLen = strlen($doc_prefix) + 1;
    $sql = "SELECT CAST(SUBSTR(invoice_num, :plen) AS UNSIGNED) AS srl_no
              FROM table_invoice_header
             WHERE biz_id = :biz
               AND txn_type = :txn_type
               AND invoice_num IS NOT NULL
               AND invoice_num LIKE :pref
             ORDER BY invoice_id DESC
             LIMIT 1";
    $st = $dbh->prepare($sql);
    $st->execute([
        ':plen'     => $prefixLen,
        ':biz'      => $biz_id,
        ':txn_type' => $txn_type,
        ':pref'     => $doc_prefix . '%'
    ]);
    $srl = (int)$st->fetchColumn();
    $srl = $srl ? ($srl + 1) : $sno_start;

    return $doc_prefix . substr(str_repeat($sno_pad, $len_sno) . $srl, -$len_sno);
}

function calcGstSplit(string $gstTxnType, float $base, float $gstPct): array {
    if ($gstTxnType === 'local') {
        $cgst = $base * ($gstPct / 200.0);
        $sgst = $base * ($gstPct / 200.0);
        return [$cgst, $sgst, 0.0, $cgst + $sgst];
    }

    $igst = $base * ($gstPct / 100.0);
    return [0.0, 0.0, $igst, $igst];
}

function ledger_id_by_name(PDO $dbh, int $biz_id, string $ledger_name): int {
    $q = $dbh->prepare("SELECT account_id FROM account_ledger WHERE biz_id = :b AND account_name = :n LIMIT 1");
    $q->execute([':b' => $biz_id, ':n' => $ledger_name]);
    $id = $q->fetchColumn();
    if (!$id) { throw new RuntimeException("System ledger missing: {$ledger_name}"); }
    return (int)$id;
}

$errors = [];
$saved  = false;
$invoice_id = 0;
$invoice_num = '';
$self_url = strtok($_SERVER['REQUEST_URI'] ?? basename($_SERVER['PHP_SELF']), '?');
if ($self_url === false || $self_url === '') {
    $self_url = basename($_SERVER['PHP_SELF']);
}

$success_flash = null;
if (!empty($_SESSION['purchase_add_success']) && is_array($_SESSION['purchase_add_success'])) {
    $success_flash = $_SESSION['purchase_add_success'];
    unset($_SESSION['purchase_add_success']);
}

if (isset($_POST['save_purchase']) && $_POST['save_purchase'] === '1') {
    try {
        $dbh->beginTransaction();

        $voucher_num  = trim((string)($_POST['voucher_num'] ?? ''));
        $voucher_date = (string)($_POST['voucher_date'] ?? date('Y-m-d'));

        $sup_invoice_num = trim((string)($_POST['sup_invoice_num'] ?? ''));
        if ($sup_invoice_num !== '') {
            $sup_invoice_date = trim((string)($_POST['sup_invoice_date'] ?? ''));
            if ($sup_invoice_date === '') {
                throw new RuntimeException('Supplier invoice date is required when supplier invoice number is entered.');
            }
        } else {
            $sup_invoice_date = null;
        }

        $vendor_id      = (int)($_POST['vendor_id'] ?? 0);
        $vendor_name    = trim((string)($_POST['vendor_name'] ?? ''));
        $vendor_address = trim((string)($_POST['vendor_address'] ?? ''));
        $vendor_phone   = trim((string)($_POST['vendor_phone'] ?? ''));
        $vendor_state   = trim((string)($_POST['vendor_state'] ?? ''));
        $vendor_pincode = trim((string)($_POST['vendor_pincode'] ?? ''));
        $vendor_gstin   = trim((string)($_POST['vendor_gstin'] ?? ''));
        $remark_txn     = trim((string)($_POST['remark_txn'] ?? ''));

        if ($vendor_id <= 0) {
            throw new RuntimeException('Select a supplier first.');
        }

        $gst_txn_type = 'local';
        if ($vendor_state !== '' && isset($comp_state)) {
            if (strtoupper(trim($vendor_state)) !== strtoupper(trim((string)$comp_state))) {
                $gst_txn_type = 'interstate';
            }
        }

        if ($voucher_num === '' || empty($_POST['manual'])) {
            $invoice_num = nextDocNumber($dbh, $biz_id, $txn_type);
        } else {
            $invoice_num = $voucher_num;
        }

        $chk = $dbh->prepare("SELECT COUNT(*)
                                FROM table_invoice_header
                               WHERE biz_id = :biz_id
                                 AND txn_type = :txn_type
                                 AND invoice_num = :invoice_num");
        $chk->execute([
            ':biz_id'      => $biz_id,
            ':txn_type'    => $txn_type,
            ':invoice_num' => $invoice_num
        ]);
        if ((int)$chk->fetchColumn() > 0) {
            throw new RuntimeException('Purchase voucher number already exists.');
        }

        // Block duplicate supplier invoice for the same supplier, invoice number and invoice date.
        // This prevents entering the same supplier bill twice and shows the existing purchase voucher number.
        if ($sup_invoice_num !== '' && $sup_invoice_date !== null) {
            $supChk = $dbh->prepare("\n                SELECT invoice_num\n                FROM table_invoice_header\n                WHERE biz_id = :biz_id\n                  AND txn_type = :txn_type\n                  AND invoice_cust_id = :vendor_id\n                  AND ref_doc_no = :sup_invoice_num\n                  AND ref_doc_date = :sup_invoice_date\n                LIMIT 1\n            ");
            $supChk->execute([
                ':biz_id' => $biz_id,
                ':txn_type' => $txn_type,
                ':vendor_id' => $vendor_id,
                ':sup_invoice_num' => $sup_invoice_num,
                ':sup_invoice_date' => $sup_invoice_date
            ]);
            $existingVoucher = $supChk->fetchColumn();
            if ($existingVoucher) {
                throw new RuntimeException("Supplier invoice number/date already added in Purchase Voucher: {$existingVoucher}");
            }
        }

        $sqlH = "INSERT INTO table_invoice_header
                 (txn_type, biz_id, invoice_num, invoice_dt,
                  ref_doc_no, ref_doc_date, note,
                  invoice_cust_id, cust_name,
                  bill_to_address, bill_to_state, bill_to_pincode, bill_to_phone, bill_to_gstin,
                  gst_txn_type, invoice_created_by, created_dtm)
                 VALUES
                 (:txn_type, :biz, :inv_num, :inv_dt,
                  :ref_no, :ref_dt, :note,
                  :cust_id, :cust_name,
                  :addr, :state, :pincode, :phone, :gstin,
                  :gst_txn_type, :created_by, :created_dtm)";
        $sth = $dbh->prepare($sqlH);
        $sth->execute([
            ':txn_type'     => $txn_type,
            ':biz'          => $biz_id,
            ':inv_num'      => $invoice_num,
            ':inv_dt'       => $voucher_date,
            ':ref_no'       => $sup_invoice_num,
            ':ref_dt'       => $sup_invoice_date,
            ':note'         => $remark_txn,
            ':cust_id'      => $vendor_id,
            ':cust_name'    => $vendor_name,
            ':addr'         => $vendor_address,
            ':state'        => $vendor_state,
            ':pincode'      => $vendor_pincode,
            ':phone'        => $vendor_phone,
            ':gstin'        => $vendor_gstin,
            ':gst_txn_type' => $gst_txn_type,
            ':created_by'   => $login_user,
            ':created_dtm'  => $dtm
        ]);

        $invoice_id = (int)$dbh->lastInsertId();
        if ($invoice_id <= 0) {
            throw new RuntimeException('Failed to create purchase header.');
        }

        $item_ids     = $_POST['item_id']     ?? [];
        $item_types   = $_POST['item_type']   ?? [];
        $item_names   = $_POST['item_name']   ?? [];
        $remark_items = $_POST['remark_item'] ?? [];
        $hsn_sacs     = $_POST['hsn_sac']     ?? [];
        $uoms         = $_POST['uom']         ?? [];
        $prices       = $_POST['item_price']  ?? [];
        $qtys         = $_POST['quantity']    ?? [];
        $gsts         = $_POST['itemGST']     ?? [];
        $disc_modes   = $_POST['discMode']    ?? [];
        $disc_amts    = $_POST['discAmt']     ?? [];

        if (!is_array($item_ids) || count($item_ids) === 0) {
            throw new RuntimeException('Add at least one line item.');
        }

        $sqlD = "INSERT INTO table_invoice_details
                 (biz_id, parent_invoice_id, item_srl_no, item_id, item_type, item_name, item_note,
                  uom, qty, price, discount_mode, discount_amt, discount_pct,
                  total_amt, hsn_code, gst_pct, CGST, SGST, IGST, gst_amt)
                 VALUES
                 (:biz_id, :pid, :sno, :item_id, :item_type, :item_name, :item_note,
                  :uom, :qty, :price, :discount_mode, :discount_amt, :discount_pct,
                  :sub_total, :hsn, :gst_pct, :cgst, :sgst, :igst, :gst_amt)";
        $std = $dbh->prepare($sqlD);

        $totalSubtotal = 0.0;
        $totalCGST     = 0.0;
        $totalSGST     = 0.0;
        $totalIGST     = 0.0;
        $totalGST      = 0.0;
        $round_off_amt = 0.0;
        $saved_line_count = 0;
        $effective_lines  = 0;
        $item_srl_no      = 0;

        for ($i = 0; $i < count($item_ids); $i++) {
            $item_id     = (int)($item_ids[$i] ?? 0);
            $item_type   = strtoupper(trim((string)($item_types[$i] ?? '')));
            $item_name   = trim((string)($item_names[$i] ?? ''));
            $remark_item = trim((string)($remark_items[$i] ?? ''));
            $hsn         = trim((string)($hsn_sacs[$i] ?? ''));
            $uom         = trim((string)($uoms[$i] ?? ''));
            $price       = (float)($prices[$i] ?? 0);
            $qty         = (float)($qtys[$i] ?? 0);
            $gstPct      = (float)($gsts[$i] ?? 0);
            $discMode    = strtoupper(trim((string)($disc_modes[$i] ?? 'NONE')));
            $discVal     = (float)($disc_amts[$i] ?? 0);

            if ($item_name === '') {
                continue;
            }

            if ($item_type === 'ROUND_OFF') {
                $qty = 1;
                $gstPct = 0;
                $discMode = 'NONE';
                $discount_amt = 0.0;
                $discount_pct = 0.0;
                $finalPrice = $price;
            } else {
                if ($qty < 0) $qty = 0;
                if ($price < 0) $price = 0;
                if ($gstPct < 0) $gstPct = 0;

                $discount_amt = 0.0;
                $discount_pct = 0.0;

                if ($discMode === 'AMT') {
                    if ($discVal < 0) $discVal = 0;
                    if ($discVal > $price) $discVal = $price;
                    $discount_amt = $discVal;
                    $finalPrice = $price - $discVal;
                } elseif ($discMode === 'PCT') {
                    if ($discVal < 0) $discVal = 0;
                    if ($discVal > 100) $discVal = 100;
                    $discount_pct = $discVal;
                    $finalPrice = $price - (($price * $discVal) / 100.0);
                } else {
                    $discMode = 'NONE';
                    $finalPrice = $price;
                }

                if ($finalPrice < 0) $finalPrice = 0;
            }

            if ($qty <= 0) {
                continue;
            }

            $subTotal = $finalPrice * $qty;

            if ($item_type === 'ROUND_OFF') {
                $cgst = 0.0;
                $sgst = 0.0;
                $igst = 0.0;
                $gst_amt = 0.0;
            } else {
                [$cgst, $sgst, $igst, $gst_amt] = calcGstSplit($gst_txn_type, $subTotal, $gstPct);
            }

            $item_srl_no++;
            $std->execute([
                ':biz_id'        => $biz_id,
                ':pid'           => $invoice_id,
                ':sno'           => $item_srl_no,
                ':item_id'       => $item_id,
                ':item_type'     => $item_type,
                ':item_name'     => $item_name,
                ':item_note'     => $remark_item,
                ':uom'           => $uom,
                ':qty'           => $qty,
                ':price'         => $price,
                ':discount_mode' => $discMode,
                ':discount_amt'  => $discount_amt,
                ':discount_pct'  => $discount_pct,
                ':sub_total'     => $subTotal,
                ':hsn'           => $hsn,
                ':gst_pct'       => $gstPct,
                ':cgst'          => $cgst,
                ':sgst'          => $sgst,
                ':igst'          => $igst,
                ':gst_amt'       => $gst_amt
            ]);
            $detail_id = (int)$dbh->lastInsertId();
            $saved_line_count++;

            $is_inventory_item = ($item_type !== 'CHARGE' && $item_type !== 'ROUND_OFF');
            if ($is_inventory_item && $item_id > 0 && $qty > 0) {
                $newQty = $item->addItemQty($dbh, $biz_id, $item_id, $qty);
                $stk_j->insert_stock_journal(
                    $biz_id, $item_id, 0, $qty, $newQty,
                    "Purchase:{$invoice_num}", $invoice_id, $detail_id,
                    $login_user, $dtm
                );
            }

            if ($item_type === 'ROUND_OFF') {
                $round_off_amt += $subTotal;
            } else {
                $totalSubtotal += $subTotal;
                $totalCGST     += $cgst;
                $totalSGST     += $sgst;
                $totalIGST     += $igst;
                $totalGST      += $gst_amt;
            }

            if ($item_type !== 'ROUND_OFF' && $qty > 0 && $finalPrice > 0) {
                $effective_lines++;
            }
        }

        if ($saved_line_count === 0) {
            throw new RuntimeException('Add at least one valid line item.');
        }
        if ($effective_lines === 0) {
            throw new RuntimeException('Add at least one valid purchase item or charge. Round Off alone is not allowed.');
        }

        $untaxed  = round((float)$totalSubtotal, 2);
        $taxCGST  = round((float)$totalCGST, 2);
        $taxSGST  = round((float)$totalSGST, 2);
        $taxIGST  = round((float)$totalIGST, 2);
        $taxTotal = round((float)$totalGST, 2);
        $grand    = round($untaxed + $taxTotal + $round_off_amt, 0);

        if ($grand <= 0) {
            throw new RuntimeException('Purchase voucher amount must be greater than zero.');
        }

        $sqlU = "UPDATE table_invoice_header
                    SET total_amt = :sub,
                        CGST = :cgst,
                        SGST = :sgst,
                        IGST = :igst,
                        total_tax = :ttax,
                        net_amt = :net_amt
                  WHERE biz_id = :biz AND invoice_id = :iid";
        $stu = $dbh->prepare($sqlU);
        $stu->execute([
            ':sub'     => $untaxed,
            ':cgst'    => $taxCGST,
            ':sgst'    => $taxSGST,
            ':igst'    => $taxIGST,
            ':ttax'    => $taxTotal,
            ':net_amt' => $grand,
            ':biz'     => $biz_id,
            ':iid'     => $invoice_id
        ]);

        $L_PURCHASE = ledger_id_by_name($dbh, $biz_id, 'Purchase Accounts');
        $L_CGST     = ($taxCGST > 0) ? ledger_id_by_name($dbh, $biz_id, 'Input CGST') : null;
        $L_SGST     = ($taxSGST > 0) ? ledger_id_by_name($dbh, $biz_id, 'Input SGST') : null;
        $L_IGST     = ($taxIGST > 0) ? ledger_id_by_name($dbh, $biz_id, 'Input IGST') : null;
        $L_ROUND    = ($round_off_amt != 0.0) ? ledger_id_by_name($dbh, $biz_id, 'Rounding Difference') : null;
        $L_AP       = (int)$vendor_id;

        if ($L_AP <= 0) {
            throw new RuntimeException('Invalid vendor ledger.');
        }

        $lines = [];
        if ($untaxed != 0.0) $lines[] = ['ledger_id' => $L_PURCHASE, 'debit' => $untaxed];
        if ($L_CGST && $taxCGST != 0.0) $lines[] = ['ledger_id' => $L_CGST, 'debit' => $taxCGST];
        if ($L_SGST && $taxSGST != 0.0) $lines[] = ['ledger_id' => $L_SGST, 'debit' => $taxSGST];
        if ($L_IGST && $taxIGST != 0.0) $lines[] = ['ledger_id' => $L_IGST, 'debit' => $taxIGST];

        // Purchase round-off direction:
        // round_off_amt > 0 increases vendor payable, so it is an extra debit.
        // round_off_amt < 0 decreases vendor payable, so it is a credit.
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

        $ret = $lj->postDoubleEntry(
            $biz_id,
            $voucher_date,
            'PURCHASE',
            $invoice_id,
            $invoice_num,
            $login_user,
            $lines
        );
        if (!($ret > 0)) {
            throw new RuntimeException("Ledger posting failed (code {$ret}).");
        }

        $dbh->commit();

        $_SESSION['purchase_add_success'] = [
            'invoice_num' => $invoice_num,
            'invoice_id'  => $invoice_id
        ];

        header('Location: ' . $self_url . '?saved=1');
        exit;

    } catch (Throwable $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        $errors[] = $e->getMessage();
        if ($debug) error_log('PURCHASE SAVE ERR: ' . $e->getMessage());
    }
}

$voucher_num = nextDocNumber($dbh, $biz_id, $txn_type);
?>
<!doctype html>
<html lang="en">
<head>
  <title>Purchase Entry</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

  <style>
    body { background:#ccf2ff; }
    .fld8 { width: 8ch; max-width: 8ch; }
    .fld12 { width: 12ch; max-width: 12ch; }
    .totbox { font-weight: bold; }
    .totrow td { background:#f5f5f5; }
    td.disc-mode { min-width: 90px; }
    td.disc-val { min-width: 90px; }
    td.disc-mode select { height: 30px; padding: 4px 6px; }
    #ItemDetailsPanel .form-control { height: 28px; padding: 3px 6px; font-size: 12px; }
    #ItemDetailsPanel textarea { resize: vertical; }
    #ItemDetailsPanel th { white-space: nowrap; font-size: 12px; }
    #ItemDetailsPanel td { vertical-align: middle; }
  </style>

  <script>
  $(function(){
    <?php if (!empty($errors)): ?>
      alert(<?= json_encode("Error:\n- " . implode("\n- ", $errors)) ?>);
    <?php endif; ?>
  });

  function searchName(){
    var biz_id = $('#biz_id').val();
    var cust_name = $('#srch_cust_name').val();
    $.post('party-search-name-ajax.php',
      {p_act_grp:'vendor', p_biz_id:biz_id, p_cust_name:cust_name},
      function(html){ $('#searchOutput').html(html).show(); });
  }

  function searchPhone(){
    var biz_id = $('#biz_id').val();
    var phone  = $('#srch_cust_number').val();
    $.post('party-search-contact-ajax.php',
      {p_act_grp:'vendor', p_biz_id:biz_id, p_cust_number:phone},
      function(html){ $('#searchOutput').html(html).show(); });
  }

  function searchEmail(){
    var biz_id = $('#biz_id').val();
    var email  = $('#srch_cust_email').val();
    $.post('party-search-email-ajax.php',
      {p_act_grp:'vendor', p_biz_id:biz_id, p_cust_email:email},
      function(html){ $('#searchOutput').html(html).show(); });
  }

  function set_party(val){
    var parts = (val || '').split(':');
    $.post('party-info-fetch-ajax.php', {cust_id: parts[0]}, function(resp){
      var obj = JSON.parse(resp || '{}');
      $('#vendor_id').val(obj.account_id || '');
      $('#vendor_name').val(obj.account_name || '');
      $('#vendor_address').val(obj.address || '');
      $('#vendor_phone').val(obj.phone_num || '');
      $('#vendor_state').val(obj.state || '');
      $('#vendor_pincode').val(obj.pincode || '');
      $('#vendor_gstin').val(obj.gstin || '');
      recalcAllTotalsPurchase();
      updateRoundOffIfPresentPurchase();
      $('#sup_invoice_num').focus();
    });
  }

  function addParty(){
    $.post('bill-customer-add-ajax.php', {
      act_grp:'vendor',
      cst_name: $('#cst_name').val(),
      cst_number: $('#cst_number').val(),
      cst_add: $('#cst_address').val(),
      cst_email: $('#cst_email').val(),
      cst_gstin: $('#cst_gstin').val(),
      cst_state: $('#cst_state').val()
    }, function(response){ set_party(response); });
    return false;
  }

  function set_voucher_numbering_mode(){
    document.getElementById('manual').checked = true;
  }
  </script>
</head>
<body>
<div><?php include 'header.inc.php'; ?></div>

<main class="container">
  <center><h3 class="text-primary" style="margin-top:30px;">Purchase Entry</h3></center>

  <form id="purchaseForm" method="POST">
	 <input type="hidden" name="save_purchase" value="1">
    <input type="hidden" id="biz_id" name="biz_id" value="<?= (int)$biz_id ?>">

    <div class="form-group row">
      <label class="control-label col-md-2">Purchase Voucher No<span style="color:red">*</span></label>
      <div class="col-md-3">
        <input name="voucher_num" id="voucher_num" class="input-md" type="text" value="<?= h($voucher_num) ?>" onchange="set_voucher_numbering_mode()">
        <br><label><input type="checkbox" name="manual" id="manual"> Manual Numbering</label>
      </div>

      <label class="control-label col-md-2">Entry Date<span style="color:red">*</span></label>
      <div class="col-md-3">
        <input name="voucher_date" id="voucher_date" required class="input-md" type="date" value="<?= h(date('Y-m-d')) ?>">
      </div>
    </div>

    <div class="form-group row">
      <label class="control-label col-md-2"><b>Supplier Details:</b></label>
      <div class="col-md-2">
        <button type="button" class="btn btn-info" data-toggle="modal" data-target="#PartyModal">Select Supplier</button>
      </div>
    </div>

    <div class="row" style="margin-bottom:2px;">
      <label class="control-label col-md-2">Vendor ID & Name</label>
      <div class="col-md-1"><input readonly name="vendor_id" id="vendor_id" class="input-md" type="text"></div>
      <div class="col-md-2"><input readonly name="vendor_name" id="vendor_name" class="input-md" type="text"></div>
      <label class="control-label col-md-2">Phone</label>
      <div class="col-md-3"><input readonly name="vendor_phone" id="vendor_phone" class="input-md" type="text"></div>
    </div>

    <div class="row" style="margin-bottom:2px;">
      <label class="control-label col-md-2">Address</label>
      <div class="col-md-3"><input readonly name="vendor_address" id="vendor_address" class="input-md" type="text"></div>
      <label class="control-label col-md-2">PinCode</label>
      <div class="col-md-2"><input readonly name="vendor_pincode" id="vendor_pincode" class="input-md" type="text"></div>
    </div>

    <div class="row" style="margin-bottom:2px;">
      <label class="control-label col-md-2">State</label>
      <div class="col-md-2"><input readonly name="vendor_state" id="vendor_state" class="input-md" type="text"></div>
      <div class="col-md-1"></div>
      <label class="control-label col-md-2">GSTIN</label>
      <div class="col-md-3"><input readonly name="vendor_gstin" id="vendor_gstin" class="input-md" type="text"></div>
    </div>

    <div class="row" style="margin-bottom:2px; margin-top:10px;">
      <label class="control-label col-md-2">Supplier Invoice Number</label>
      <div class="col-md-2"><input name="sup_invoice_num" id="sup_invoice_num" class="input-md" type="text"></div>
      <div class="col-md-1"></div>
      <label class="control-label col-md-2">Supplier Invoice Date</label>
      <div class="col-md-3"><input name="sup_invoice_date" id="sup_invoice_date" class="input-md" type="date" value="" disabled></div>
    </div>

    <div class="row" style="margin-bottom:2px;margin-top:10px;<?= ($allow_remark_txn === 'N') ? 'display:none;' : '' ?>">
      <label class="control-label col-md-2" for="remark_txn">Remark</label>
      <div class="col-md-10">
        <input name="remark_txn" id="remark_txn" class="form-control" type="text">
      </div>
    </div>

    <div class="panel panel-default" id="ItemDetailsPanel" style="margin-top:15px;">
      <div class="panel-heading" style="display:flex; align-items:center; justify-content:space-between;">
        <strong>Line Items</strong>
        <div>
          <button type="button" class="btn btn-warning btn-xs" id="btnAddRoundOff">Add Round Off</button>
          <button type="button" class="btn btn-primary btn-xs" id="btnOpenItemModal" data-toggle="modal" data-target="#ItemModal" style="margin-left:6px;">Add Item</button>
        </div>
      </div>

      <div class="panel-body" style="padding:0;">
        <div class="table-responsive">
          <table class="table table-hover table-condensed" style="margin:0;">
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
            <tbody id="js1"></tbody>
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
    </div>

    <div style="margin-top:10px;">
      <button name="submit" class="btn btn-success" type="submit" value="submit">SUBMIT</button>
    </div>
  </form>
</main>

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
              <div class="row">
                <div class="col-md-2"><b>Name:</b></div>
                <div class="col-md-8">
                  <input id="srch_cust_name" placeholder="Name" type="text" value="">
                  <button type="button" onclick="searchName()">Search</button>
                </div>
              </div>
              <div class="row">
                <div class="col-md-2"><b>Contact:</b></div>
                <div class="col-md-8">
                  <input id="srch_cust_number" placeholder="Phone Number" type="text" value="">
                  <button type="button" onclick="searchPhone()">Search</button>
                </div>
              </div>
              <div class="row">
                <div class="col-md-2"><b>Email:</b></div>
                <div class="col-md-8">
                  <input id="srch_cust_email" placeholder="Email" type="text" value="">
                  <button type="button" onclick="searchEmail()">Search</button>
                </div>
              </div>
              <hr>
              <div id="searchOutput" style="display:none; border:1px solid #ccc;"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($success_flash): ?>
<div class="modal fade" id="purchaseSuccessModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
  <div class="modal-dialog modal-sm" role="document">
    <div class="modal-content">
      <div class="modal-header" style="background:#5cb85c;color:#fff;">
        <h4 class="modal-title">Purchase Voucher Added</h4>
      </div>
      <div class="modal-body">
        <p>Purchase voucher has been added successfully.</p>
        <p style="font-size:16px;">Voucher Number:<br><strong><?= h($success_flash["invoice_num"] ?? "") ?></strong></p>
      </div>
      <div class="modal-footer" style="text-align:center;">
        <a class="btn btn-primary" href="<?= h($self_url) ?>">Add Another</a>
        <a class="btn btn-default" href="<?= h($purchase_list_url) ?>">Go to List Purchase Voucher</a>
      </div>
    </div>
  </div>
</div>
<script>
$(function(){
  $("#purchaseSuccessModal").modal("show");
});
</script>
<?php endif; ?>

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
var purchaseRowCounter = 0;
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
  }

  var disc = 0;
  if (mode === 'AMT') disc = dval;
  else if (mode === 'PCT') disc = rate * (dval / 100.0);

  if (disc < 0) disc = 0;
  if (rate > 0 && disc > rate) disc = rate;

  var finalRate = rate - disc;
  var subTotal = qty * finalRate;
  var tax = subTotal * (gstp / 100.0);
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
  if (itemIdStr !== '') {
    var exists = $('#js1 input[name="item_id[]"]').filter(function(){ return this.value == itemId; }).length;
    if (exists) { alert('Item already added. Change qty in existing line.'); return; }
  }

  var $tr = $('<tr/>', { id: 'prodRow_' + t });

  var $nameTd = $('<td/>');
  $nameTd.append(
    $('<input/>', { type:'hidden', name:'item_id[]', id:'item_id_' + t, value:itemId }),
    $('<input/>', { type:'hidden', name:'item_type[]', id:'item_type_' + t, value:itemType }),
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

  $tr.append($('<td/>').append(
    $('<button/>', { type:'button', class:'btn btn-danger btn-xs' }).text('X').on('click', function(){ removeRowPurchase(t); })
  ));

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
    var typ = String($('#item_type_' + t).val() || '').toUpperCase();
    if (typ === 'ROUND_OFF') { found = t; return false; }
  });
  return found;
}

function sumNetExcludingRoundOffPurchase(){
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
  $('#ItemModal').on('shown.bs.modal', function () {
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

  $('#sup_invoice_num').on('input change', function () {
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
  });

  $('#purchaseForm').on('submit', function (e) {
    var $form = $(this);

    if ($form.data('saving') === true) {
      e.preventDefault();
      return false;
    }

    if ($.trim($('#vendor_id').val() || '') === '') {
      alert('Select a supplier first.');
      e.preventDefault();
      return false;
    }

    var hasAnyLine = false;
    var hasValidLine = false;

    $('#js1 tr[id^="prodRow_"]').each(function () {
      hasAnyLine = true;
      var t = this.id.split('_')[1];
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
