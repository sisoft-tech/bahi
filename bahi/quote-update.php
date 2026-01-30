<?php
ob_start();
session_start();

include 'include/session.php';
include 'include/param.php';  //Check Usage ??
include 'include/dbo.php';

/*
File Name : quote-update.php

Update Quote (QUOTE)
- Single file: Load + UI + Update handler 
- Line handling uses rec_status per row: new/upd/del
- Row identification for update/delete uses quote_detail_id (PK)
- GST txn type is auto-computed from company state vs party state (local/interstate)
- Discount is editable: mode NONE/PCT/AMT + inputs

Tables:
- quote_header (biz_id, quote_id, quote_num, quote_dt, party_*, gst_txn_type, note, updated_*, quote_status, valid_upto)
- quote_details (quote_detail_id PK, biz_id, parent_quote_id, item_*, qty, price, discount_*, taxable_amt, gst_*, item_note)
*/

$debug = 0;
checksession();
$dtm        = getLocalDtm();
$ip_address = $_SERVER['REMOTE_ADDR'];
$login_user = $_SESSION['pos_login'] ?? '';
$biz_id     = (int)($_SESSION['biz_id'] ?? 0);

include 'company-info.php'; // expects $comp_state (like DC)

$dbh = new dbo();
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$item_obj = new Item();

$doc_type = 'QUOTE';
include 'config-doc-entry-info.php'; // outputs: $allow_remark_txn ; $allow_remark_item

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
function s($v): string { return trim((string)($v ?? '')); }
function n0($v): float {
  if ($v === null) return 0.0;
  $t = trim((string)$v);
  if ($t === '') return 0.0;
  return (float)str_replace(',', '', $t);
}
function i0($v): int {
  if ($v === null) return 0;
  $t = trim((string)$v);
  if ($t === '') return 0;
  return (int)$t;
}
function r2($v): float { return round((float)$v, 2); }
function dateAddDays(string $yyyy_mm_dd, int $days): ?string {
  $t = strtotime($yyyy_mm_dd);
  if (!$t) return null;
  return date('Y-m-d', strtotime("+$days day", $t));
}

// ------------------------------------------------------------
// Inputs / routing
// ------------------------------------------------------------
$src_loc = s($_REQUEST['src_loc'] ?? 'quote-manage.php');
if ($src_loc === 'quote-manage') $src_loc = 'quote-manage.php';
//if (!preg_match('/\.php$/i', $src_loc)) $src_loc = $src_loc . '.php';

$quote_id = (int)($_REQUEST['update_id'] ?? $_REQUEST['quote_id'] ?? 0);

$err_msg = '';
$ok_msg  = '';

$quote_hdr = null;
$quote_det = [];
$valid_upto_disp = '';

if ($quote_id <= 0) {
  $err_msg = 'Missing Quote id (quote_id).';
}

// ------------------------------------------------------------
// Load existing header/details for display (when opening page)
// ------------------------------------------------------------
function load_quote(PDO $dbh, int $biz_id, int $quote_id, &$quote_hdr, &$quote_det, &$valid_upto_disp, &$err_msg) {
  $quote_hdr = null;
  $quote_det = [];
  $valid_upto_disp = '';

  if ($quote_id <= 0) return;

  try {
    $stmtH = $dbh->prepare('SELECT * FROM quote_header WHERE biz_id=:biz_id AND quote_id=:quote_id LIMIT 1');
    $stmtH->execute([':biz_id'=>$biz_id, ':quote_id'=>$quote_id]);
    $quote_hdr = $stmtH->fetch(PDO::FETCH_ASSOC);

    if (!$quote_hdr) {
      $err_msg = 'Quote not found.';
      return;
    }

    $stmtD = $dbh->prepare("SELECT * FROM quote_details WHERE biz_id=:biz_id AND parent_quote_id=:quote_id 
	ORDER BY CASE
		WHEN item_type = 'CHARGE'    THEN 2
		WHEN item_type = 'ROUND_OFF' THEN 3
		ELSE 1
	END,quote_detail_id");
    $stmtD->execute([':biz_id'=>$biz_id, ':quote_id'=>$quote_id]);
    $quote_det = $stmtD->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($quote_hdr['valid_upto'])) {
      $valid_upto_disp = (string)$quote_hdr['valid_upto'];
    }
  } catch (Throwable $e) {
    $err_msg = $e->getMessage();
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quote_update'])) {
  load_quote($dbh, $biz_id, $quote_id, $quote_hdr, $quote_det, $valid_upto_disp, $err_msg);
} else {
  // also support direct open (GET) if someone links to quote-update.php?quote_id=...
  if ($quote_id > 0) {
    load_quote($dbh, $biz_id, $quote_id, $quote_hdr, $quote_det, $valid_upto_disp, $err_msg);
  }
}

// ------------------------------------------------------------
// DELETE action
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_quote']) && $quote_id > 0) {
  try {
    if (!$dbh->inTransaction()) $dbh->beginTransaction();

    $dbh->prepare("DELETE FROM quote_details WHERE biz_id=? AND parent_quote_id=?")->execute([$biz_id, $quote_id]);
    $dbh->prepare("DELETE FROM quote_header  WHERE biz_id=? AND quote_id=?")->execute([$biz_id, $quote_id]);

    $dbh->commit();

    echo "<script>
      alert(" . json_encode("Quote deleted.") . ");
      window.location.href = " . json_encode($src_loc) . ";
    </script>";
    exit;

  } catch (Throwable $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    http_response_code(500);
    die("Delete failed: " . $e->getMessage());
  }
}

// ------------------------------------------------------------
// UPDATE handler
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit']) && $quote_id > 0) {
  try {
    // -----------------------------
    // 1) Read inputs
    // -----------------------------
    $quote_num = s($_POST['voucher_num'] ?? '');
    $quote_dt  = s($_POST['voucher_date'] ?? '');

    $party_id      = i0($_POST['party_id'] ?? 0);
    $party_name    = s($_POST['party_name'] ?? '');
    $party_address = s($_POST['party_address'] ?? '');
    $party_state   = s($_POST['party_state'] ?? '');
    $party_pincode = s($_POST['party_pincode'] ?? '');
    $party_gstin   = s($_POST['party_gstin'] ?? '');
    $party_phone   = s($_POST['party_phone'] ?? ''); // NOT NULL in schema
    $party_email   = s($_POST['party_email'] ?? ''); // NOT NULL in schema

    $note    = s($_POST['note'] ?? '');

    $quote_status = s($_POST['quote_status'] ?? 'SENT');
    if ($quote_status === '') $quote_status = 'SENT';

    $posted_valid_upto = s($_POST['valid_upto'] ?? '');

    // -----------------------------
    // 2) Compute GST txn type (local/interstate)
    // -----------------------------
    $gst_txn_type = 'local';
    if ($party_state !== '' && isset($comp_state) && $comp_state !== '') {
      $gst_txn_type = (strcasecmp($comp_state, $party_state) === 0) ? 'local' : 'interstate';
    }

    // -----------------------------
    // 3) Load existing header (for valid_upto rule)
    // -----------------------------
    $stmtOld = $dbh->prepare('SELECT quote_dt, valid_upto FROM quote_header WHERE biz_id=:biz_id AND quote_id=:quote_id LIMIT 1');
    $stmtOld->execute([':biz_id'=>$biz_id, ':quote_id'=>$quote_id]);
    $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
    if (!$old) throw new RuntimeException('Quote not found.');

    // valid_upto rule:
    // - default auto = quote_dt + 15
    // - user may override; once overridden it should not change on quote_dt changes
    $old_auto = !empty($old['quote_dt']) ? dateAddDays((string)$old['quote_dt'], 15) : null;
    $final_valid_upto = $old['valid_upto'] ?? null;

    if ($posted_valid_upto !== '') {
      $final_valid_upto = $posted_valid_upto;
    } else {
      $was_auto = ($final_valid_upto === null || $final_valid_upto === '' || ($old_auto !== null && $final_valid_upto === $old_auto));
      if ($was_auto) $final_valid_upto = dateAddDays($quote_dt, 15);
    }

    // -----------------------------
    // 4) Validate basics
    // -----------------------------
    if ($quote_num === '' || $quote_dt === '') {
      throw new RuntimeException('Quote No and Quote Date are required.');
    }
    if ($party_name === '' || $party_state === '') {
      throw new RuntimeException('Party Name and Party State are required.');
    }
    if (empty($_POST['item_id']) || !is_array($_POST['item_id'])) {
      throw new RuntimeException('At least one line item is required.');
    }

    // At least 1 non-deleted line
    $rec_status_arr = $_POST['rec_status'] ?? [];
    $n = count($_POST['item_id']);
    $any_alive = false;
    for ($i = 0; $i < $n; $i++) {
      $rs = strtolower(s($rec_status_arr[$i] ?? ''));
      if ($rs !== 'del') { $any_alive = true; break; }
    }
    if (!$any_alive) throw new RuntimeException('All line items are marked for deletion. Please keep at least one line item.');

    // Unique Quote No within biz
    $dupStmt = $dbh->prepare('SELECT quote_id FROM quote_header WHERE biz_id=:biz_id AND quote_num=:quote_num AND quote_id<>:quote_id LIMIT 1');
    $dupStmt->execute([':biz_id'=>$biz_id, ':quote_num'=>$quote_num, ':quote_id'=>$quote_id]);
    if ($dupStmt->rowCount() > 0) {
      throw new RuntimeException('Duplicate Quote No. Please use a unique number.');
    }

    // -----------------------------
    // 5) Prepared statements
    // -----------------------------
    $sqlUpdateHeader = "
      UPDATE quote_header
      SET
        quote_num     = :quote_num,
        quote_dt      = :quote_dt,
        party_id      = :party_id,
        party_name    = :party_name,
        party_address = :party_address,
        party_pincode = :party_pincode,
        party_state   = :party_state,
        party_gstin   = :party_gstin,
        party_phone   = :party_phone,
        party_email   = :party_email,
        gst_txn_type  = :gst_txn_type,
        note      = :note,
        quote_status  = :quote_status,
        valid_upto    = :valid_upto,
        updated_dtm   = :updated_dtm,
        updated_by    = :updated_by
      WHERE biz_id=:biz_id AND quote_id=:quote_id
    ";

    $sqlUpdateDetail = "
      UPDATE quote_details
      SET
        item_id        = :item_id,
		item_type 	   = :item_type,
        item_name      = :item_name,
        hsn_code       = :hsn_code,
        uom            = :uom,
        qty            = :qty,
        price          = :price,
        discount_mode  = :discount_mode,
        discount_amt   = :discount_amt,
        discount_pct   = :discount_pct,
        taxable_amt    = :taxable_amt,
        gst_pct        = :gst_pct,
        cgst_amt       = :cgst_amt,
        sgst_amt       = :sgst_amt,
        igst_amt       = :igst_amt,
        gst_amt        = :gst_amt,
        item_note      = :item_note
      WHERE biz_id=:biz_id AND parent_quote_id=:parent_quote_id AND quote_detail_id=:quote_detail_id
    ";

    $sqlDeleteDetail = "
      DELETE FROM quote_details
      WHERE biz_id=:biz_id AND parent_quote_id=:parent_quote_id AND quote_detail_id=:quote_detail_id
    ";

    $sqlInsertDetail = "
      INSERT INTO quote_details (
        biz_id, parent_quote_id,
        item_id, item_type, item_name, hsn_code, uom,
        qty, price,
        discount_mode, discount_amt, discount_pct,
        taxable_amt, gst_pct,
        cgst_amt, sgst_amt, igst_amt, gst_amt,
        item_note
      ) VALUES (
        :biz_id, :parent_quote_id,
        :item_id, :item_type, :item_name, :hsn_code, :uom,
        :qty, :price,
        :discount_mode, :discount_amt, :discount_pct,
        :taxable_amt, :gst_pct,
        :cgst_amt, :sgst_amt, :igst_amt, :gst_amt,
        :item_note
      )
    ";

    $stmtHdr  = $dbh->prepare($sqlUpdateHeader);
    $stmtUpd = $dbh->prepare($sqlUpdateDetail);
    $stmtDel = $dbh->prepare($sqlDeleteDetail);
    $stmtIns = $dbh->prepare($sqlInsertDetail);

    // Validate existing detail IDs for this quote (prevents cross-quote edits)
    $ex = $dbh->prepare('SELECT quote_detail_id FROM quote_details WHERE biz_id=:biz_id AND parent_quote_id=:quote_id');
    $ex->execute([':biz_id'=>$biz_id, ':quote_id'=>$quote_id]);
    $existing_ids = [];
    while ($r = $ex->fetch(PDO::FETCH_ASSOC)) {
      $existing_ids[(int)$r['quote_detail_id']] = true;
    }

    // -----------------------------
    // 6) Execute
    // -----------------------------
    if (!$dbh->inTransaction()) $dbh->beginTransaction();

    // 6.1 Header update
    $stmtHdr->execute([
      ':quote_num'     => $quote_num,
      ':quote_dt'      => $quote_dt,
      ':party_id'      => ($party_id > 0 ? $party_id : 0),
      ':party_name'    => $party_name,
      ':party_address' => $party_address,
      ':party_pincode' => ($party_pincode !== '' ? $party_pincode : null),
      ':party_state'   => ($party_state !== '' ? $party_state : null),
      ':party_gstin'   => ($party_gstin !== '' ? $party_gstin : null),
      ':party_phone'   => $party_phone,   // NOT NULL in schema
      ':party_email'   => $party_email,   // NOT NULL in schema
      ':gst_txn_type'  => ($gst_txn_type === 'interstate' ? 'interstate' : 'local'),
      ':note'      => ($note !== '' ? $note : null),
      ':quote_status'  => $quote_status,
      ':valid_upto'    => ($final_valid_upto !== '' ? $final_valid_upto : null),
      ':updated_dtm'   => $dtm,
      ':updated_by'    => $login_user,
      ':biz_id'        => $biz_id,
      ':quote_id'      => $quote_id,
    ]);

    // 6.2 Details upsert/delete
    $detail_id_arr     = $_POST['quote_detail_id'] ?? [];
    $item_id_arr       = $_POST['item_id'] ?? [];
    $item_type_arr     = $_POST['item_type'] ?? [];	
    $item_name_arr     = $_POST['item_name'] ?? [];
    $hsn_arr           = $_POST['hsn_code'] ?? [];
    $uom_arr           = $_POST['uom'] ?? [];
    $qty_arr           = $_POST['qty'] ?? [];
    $price_arr         = $_POST['price'] ?? [];
    $disc_mode_arr     = $_POST['discount_mode'] ?? [];
    $disc_num_arr      = $_POST['discount_num'] ?? [];
	
//    $disc_amt_arr      = $_POST['discount_amt'] ?? [];
//    $disc_pct_arr      = $_POST['discount_pct'] ?? [];

    $gst_pct_arr       = $_POST['gst_pct'] ?? [];
    $item_note_arr     = $_POST['item_note'] ?? [];

    for ($i = 0; $i < $n; $i++) {
      $rs = strtolower(s($rec_status_arr[$i] ?? ''));
      $qid = (int)($detail_id_arr[$i] ?? 0);

      // normalize if missing
      if ($rs === '') $rs = ($qid > 0 ? 'upd' : 'new');

      // Delete
      if ($rs === 'del') {
        if ($qid > 0 && isset($existing_ids[$qid])) {
          $stmtDel->execute([
            ':biz_id' => $biz_id,
            ':parent_quote_id' => $quote_id,
            ':quote_detail_id' => $qid
          ]);
        }
        continue;
      }

      // Read values
      $item_id   = ($_POST['item_id'][$i] ?? '') !== '' ? (int)$_POST['item_id'][$i] : null;	  
	  $item_type = s($item_type_arr[$i] ?? "GOODS") ; 
      $item_name = s($item_name_arr[$i] ?? '');
      $hsn       = s($hsn_arr[$i] ?? '');
      $uom       = s($uom_arr[$i] ?? '');
      $qty       = (float)n0($qty_arr[$i] ?? 0);
      $price     = (float)n0($price_arr[$i] ?? 0);

      $disc_mode = strtoupper(s($disc_mode_arr[$i] ?? 'NONE'));
      if (!in_array($disc_mode, ['NONE','PCT','AMT'], true)) $disc_mode = 'NONE';

//      $disc_amt_in = (float)n0($disc_amt_arr[$i] ?? 0);
//      $disc_pct_in = (float)n0($disc_pct_arr[$i] ?? 0);
      $disc_num_in = (float)n0($disc_num_arr[$i] ?? 0);


      $gst_pct   = (float)n0($gst_pct_arr[$i] ?? 0);
      $item_note = s($item_note_arr[$i] ?? '');

      if ($item_id === null) continue;
      if ($qty <= 0) $qty = 0.0;

      // Compute amounts

      $disc_amt = 0.0;
      $disc_pct = 0.0;

// discount is per unit either in amt or price.

      if ($disc_mode === 'PCT') {
        $disc_pct = $disc_num_in;
        if ($disc_pct < 0) $disc_pct = 0;
        if ($disc_pct > 99.99) $disc_pct = 99.99;
        $disc_amt = r2(($price * $disc_pct) / 100.0);
      } elseif ($disc_mode === 'AMT') {
        $disc_amt = $disc_num_in;
        if ($disc_amt < 0) $disc_amt = 0;
        if ($disc_amt > $price) $disc_amt = $price;
        $disc_amt = r2($disc_amt);
        $disc_pct = ($price > 0) ? r2(($disc_amt / $price) * 100.0) : 0.0;
      } else {
        $disc_amt = 0.0;
        $disc_pct = 0.0;
      }

	  $final_price = $price - $disc_amt ;

      $taxable = r2($qty * $final_price);
	  if (strtoupper($item_type) === 'ROUND_OFF') {
	  // allow negative round-off
	  // keep gst_pct forced to 0 (already set in UI and should be enforced too)
	  $gst_pct = 0.0;
	} else {
	  if ($taxable < 0) $taxable = 0.0;
	}
	  
      // round split first, then gst_amt
      $cgst = 0.0; $sgst = 0.0; $igst = 0.0;
      if ($gst_txn_type === 'interstate') {
        $igst = r2(($taxable * $gst_pct) / 100.0);
      } else {
        $half = ($gst_pct / 2.0);
        $cgst = r2(($taxable * $half) / 100.0);
        $sgst = r2(($taxable * $half) / 100.0);
      }
      $gst_amt = r2($cgst + $sgst + $igst);

      $payload = [
        ':biz_id'          => $biz_id,
        ':parent_quote_id' => $quote_id,
        ':item_id'         => $item_id,
		':item_type'	   => $item_type,
        ':item_name'       => $item_name,
        ':hsn_code'        => ($hsn !== '' ? $hsn : null),
        ':uom'             => ($uom !== '' ? $uom : null),
        ':qty'             => $qty,
        ':price'           => r2($price),
        ':discount_mode'   => $disc_mode,
        ':discount_amt'    => r2($disc_amt),
        ':discount_pct'    => r2($disc_pct),
        ':taxable_amt'     => r2($taxable),
        ':gst_pct'         => r2($gst_pct),
        ':cgst_amt'        => r2($cgst),
        ':sgst_amt'        => r2($sgst),
        ':igst_amt'        => r2($igst),
        ':gst_amt'         => r2($gst_amt),
        ':item_note'       => ($item_note !== '' ? $item_note : null),
      ];

      if ($rs === 'upd' && $qid > 0 && isset($existing_ids[$qid])) {
        $payload[':quote_detail_id'] = $qid;
        $stmtUpd->execute($payload);
      } else {
        // Insert
        $stmtIns->execute($payload);
      }
    }

    if ($dbh->inTransaction()) $dbh->commit();

    $ok_msg = 'Quote updated: ' . $quote_num;

    echo "<script>\n";
    echo "alert(" . json_encode($ok_msg) . ");\n";
    echo "window.location.href = " . json_encode($src_loc) . ";\n";
    echo "</script>";
    exit;

  } catch (Throwable $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    $err_msg = $e->getMessage();

    // reload data for UI after error
    load_quote($dbh, $biz_id, $quote_id, $quote_hdr, $quote_det, $valid_upto_disp, $err_msg);
  }
}

// Convenience getter
$val = function($k, $default='') use ($quote_hdr) {
  if (!$quote_hdr) return $default;
  return isset($quote_hdr[$k]) && $quote_hdr[$k] !== null ? (string)$quote_hdr[$k] : $default;
};

?>
<!doctype html>
<html lang="en">
<head>
  <title>Quote Update</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=yes">

  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" type="text/css" rel="stylesheet">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

  <style>
    .fld12 { width: 12ch; max-width: 12ch; }
	.fld8 { width: 8ch; max-width: 8ch; }

    .row-del { background: #ffe6e6; opacity: 0.75; }
    .row-del input, .row-del textarea, .row-del select { pointer-events:none; }
    .numr { text-align:right; padding-right:2px; }
    .disc-wrap { display:flex; gap:6px; align-items:center; }
    .disc-wrap input { width: 10ch; }
  </style>

  <script>
    function confirmDeleteQuote(){
      var q = <?php echo json_encode($quote_hdr['quote_num'] ?? ''); ?>;
      var ok = window.confirm("Delete Quote " + q + "?\nThis will remove Header and Items.\n\nProceed?");
      if (!ok) return false;
      document.getElementById('quote_delete_form').submit();
      return false;
    }
  </script>

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
        $('#party_name_dup').val(obj.account_name||'');
        $('#party_address').val(obj.address||'');
        $('#party_phone').val(obj.phone_num||obj.phone||'');
        $('#party_email').val(obj.email||obj.email_id||'');
        $('#party_state').val(obj.state||'');
        $('#party_pincode').val(obj.pincode||'');
        $('#party_gstin').val(obj.gstin||'');

        // if you want immediate re-calc when party changes (gst txn type):
        // we recompute in server anyway; UI just shows totals.
      });
    }

    function toggleParty(cb){
      var x = document.getElementById('PartyDetails');
      x.style.display = (cb.checked ? 'block' : 'none');
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
      <div class="row" style="margin-top:50px;">
  		<div class="col-sm-1"><a href='<?php echo $src_loc;?>' style='border-radius:0'>❮ Back</a> </div>
        <div class="col-md-8">
          <h3 class="text-primary" style="text-align:center;">Quote Update</h3>
        </div>
        <div class="col-md-3 text-right">
          <form method="POST" id="quote_delete_form">
            <button type="button" class="btn btn-danger" onclick="confirmDeleteQuote()">DELETE QUOTE</button>
            <input type="hidden" name="delete_quote" value="1">
            <input type="hidden" name="biz_id" value="<?php echo htmlspecialchars((string)$biz_id); ?>">
            <input type="hidden" name="quote_id" value="<?php echo htmlspecialchars((string)$quote_id); ?>">
            <input type="hidden" name="src_loc" value="<?php echo htmlspecialchars((string)$src_loc); ?>">
          </form>
        </div>
      </div>

      <?php if ($err_msg !== ''): ?>
        <div class="alert alert-danger" style="margin-top:15px;"><?php echo htmlspecialchars($err_msg); ?></div>
      <?php endif; ?>
      <?php if ($ok_msg !== ''): ?>
        <div class="alert alert-success" style="margin-top:15px;"><?php echo htmlspecialchars($ok_msg); ?></div>
      <?php endif; ?>

      <form method="POST" id="quote_update_form">
        <input type="hidden" id="biz_id" name="biz_id" value="<?php echo htmlspecialchars((string)$biz_id); ?>">
        <input type="hidden" name="quote_id" value="<?php echo htmlspecialchars((string)$quote_id); ?>">
        <input type="hidden" name="src_loc" value="<?php echo htmlspecialchars((string)$src_loc); ?>">

        <br>

        <div class="form-group row">
          <label class="control-label col-md-2" for="voucher_num">Quote No<span style="color:red">*</span></label>
          <div class="col-md-2">
            <input name="voucher_num" id="voucher_num" required class="input-md" type="text"
                   value="<?php echo htmlspecialchars($val('quote_num','')); ?>" onchange="set_voucher_numbering_mode()">
            <br>
            <input type="checkbox" name="manual" id="manual">
            <label for="manual">Manual Numbering</label>
          </div>

          <label class="control-label col-md-2" for="voucher_date">Quote Date<span style="color:red">*</span></label>
          <div class="col-md-2">
            <input name="voucher_date" id="voucher_date" required class="input-md" type="date"
                   value="<?php echo htmlspecialchars(substr($val('quote_dt',''),0,10)); ?>">
          </div>

			<div class="col-md-4">
			  <label class="control-label col-md-6" for="valid_upto">Valid Upto</label>
	
			  <input type="date" class="input-md col-md-6" name="valid_upto" id="valid_upto"
					 value="<?php echo htmlspecialchars(substr($valid_upto_disp,0,10)); ?>">
			  <div class="vu-help">Default: Quote Date + 15. Change once to lock.</div>
			</div>


		  
        </div>

        <div class="form-group row">
          <label class="control-label col-md-2" for="BillTo"><b>Party Details:</b></label>
          <div class="col-md-2">
            <button type="button" class="btn bill_btn btn-info" data-toggle="modal" data-target="#PartyModal">Select Party</button>
          </div>
          <label class="control-label col-md-2" for="name">Party ID/Name</label>
          <div class="col-md-1">
            <input readonly name="party_id" id="party_id" class="input-md" type="text" value="<?php echo htmlspecialchars($val('party_id','')); ?>">
          </div>
          <div class="col-md-2">
            <input readonly name="party_name" id="party_name" class="input-md" type="text" value="<?php echo htmlspecialchars($val('party_name','')); ?>">
          </div>
          <div class="col-md-3">
            Show/Hide Party Details
            <input type="checkbox" checked name="cb_party_det" id="cb_party_det" class="input-md" onchange="toggleParty(this)">
          </div>
        </div>

        <div id="PartyDetails" style="display:block;">
          <div class="row" style="margin-bottom:2px;">
            <div class="col-md-12">
              <div class="col-md-12">
                <label class="control-label col-md-2" for="party_name_dup">Party Name</label>
                <div class="col-md-6">
                  <input readonly name="party_name_dup" id="party_name_dup" class="form-control" type="text" value="<?php echo htmlspecialchars($val('party_name','')); ?>">
                </div>
              </div>
              <div class="col-md-12">
                <label class="control-label col-md-2" for="party_address">Address</label>
                <div class="col-md-6">
                  <input readonly type="text" name="party_address" id="party_address" class="form-control" value="<?php echo htmlspecialchars($val('party_address','')); ?>">
                </div>
              </div>
              <div class="col-md-12">
                <label class="control-label col-md-2" for="party_state">State<span style="color:red">*</span></label>
                <div class="col-md-3">
                  <input readonly type="text" name="party_state" id="party_state" class="form-control" value="<?php echo htmlspecialchars($val('party_state','')); ?>">
                </div>
                <label class="control-label col-md-1" for="party_pincode">Pin</label>
                <div class="col-md-2">
                  <input readonly type="text" name="party_pincode" id="party_pincode" class="form-control" value="<?php echo htmlspecialchars($val('party_pincode','')); ?>">
                </div>
              </div>
              <div class="col-md-12">
                <label class="control-label col-md-2" for="party_gstin">GSTIN</label>
                <div class="col-md-3">
                  <input readonly type="text" name="party_gstin" id="party_gstin" class="form-control" value="<?php echo htmlspecialchars($val('party_gstin','')); ?>">
                </div>
              </div>
              <div class="col-md-12">
                <label class="control-label col-md-2" for="party_phone">Phone</label>
                <div class="col-md-3">
                  <input readonly type="text" name="party_phone" id="party_phone" class="form-control" value="<?php echo htmlspecialchars($val('party_phone','')); ?>">
                </div>
                <label class="control-label col-md-1" for="party_email">Email</label>
                <div class="col-md-4">
                  <input readonly type="text" name="party_email" id="party_email" class="form-control" value="<?php echo htmlspecialchars($val('party_email','')); ?>">
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="row" style="margin-top:10px;">
         
        <label class="control-label col-md-2">Status</label>
		<div class="col-md-3">	
            <input type="text" class="form-control" name="quote_status" id="quote_status" list="quote_status_list"
                   maxlength="16" value="<?php echo htmlspecialchars($val('quote_status','SENT')); ?>">
            <datalist id="quote_status_list">
              <option value="SENT"></option>
              <option value="DRAFT"></option>
              <option value="ACCEPTED"></option>
              <option value="REJECTED"></option>
              <option value="CANCELLED"></option>
            </datalist>
          </div>
        </div>

        <div class="row" style="margin-top:10px; <?php if (($allow_remark_txn ?? 'Y')=='N') echo 'display:none;'; ?>">
          <div class="col-md-12">
            <label>Message / Note (Quote level)</label>
            <textarea class="form-control" name="note" id="note" maxlength="512" rows="2"
                      placeholder="Optional message shown on quote"><?php echo htmlspecialchars($val('note','')); ?></textarea>
          </div>
        </div>

        <div class="card" style="border:1px solid #ddd; border-radius:4px; margin-top:15px;">
          <div class="card-header" style="padding:10px 15px; border-bottom:1px solid #ddd; background:#f5f5f5; font-weight:bold;">
            Line Items
            <button type="button" class="btn btn-primary btn-xs pull-right" id="btnOpenItemModal" data-toggle="modal" data-target="#ItemModal">Add Item</button>
			<button type="button" class="btn btn-warning btn-xs pull-right" id="btnAddRoundOff" style="margin-right:8px;">Add Round Off</button>
          </div>

          <div class="card-body" style="padding:0;">
            <div class="table-responsive">
              <table class="table table-hover" style="margin:0;">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>HSN</th>
                    <th>UoM</th>
                    <th class="numr">Price</th>
                    <th class="numr">Qty</th>
                    <th>Disc Mode</th>
					<th>Disc</th>
                    <th class="numr">Taxable</th>
                    <th class="numr">GST%</th>
                    <th class="numr">GST Amt</th>
                    <th class="numr">Line Total</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="js1">
                  <?php
                    $rowCounter = 0;
                    if (!empty($quote_det)) {
                      foreach ($quote_det as $r) {
                        $rowCounter++;
                        $t = $rowCounter;

                        $qid  = $r['quote_detail_id'] ?? 0;
                        $iid  = $r['item_id'] ?? '';
						$item_type = $r['item_type'] ?? '';
                        $iname= $r['item_name'] ?? '';
                        $inote= $r['item_note'] ?? '';
                        $uom  = $r['uom'] ?? '';
                        $hsn  = $r['hsn_code'] ?? '';

                        $qty  = isset($r['qty']) ? (float)$r['qty'] : 0.0;
                        $price= isset($r['price']) ? (float)$r['price'] : 0.0;
                        $gst  = isset($r['gst_pct']) ? (float)$r['gst_pct'] : 0.0;

                        $disc_mode = strtoupper($r['discount_mode'] ?? 'NONE');
                        $disc_amt  = isset($r['discount_amt']) ? (float)$r['discount_amt'] : 0.0;
                        $disc_pct  = isset($r['discount_pct']) ? (float)$r['discount_pct'] : 0.0;

                        $disc = 0.0;
						$disc_num = 0 ;
                        if ($disc_mode === 'PCT') {
							$disc = $price * ($disc_pct / 100.0);
							$disc_num = $disc_pct ;
						}
                        elseif ($disc_mode === 'AMT') {
								$disc = $disc_amt;
								$disc_num = $disc_amt;
						}
						$final_price = $price - $disc ;
						$taxable = r2($qty * $final_price);

						if (strtoupper($item_type) === 'ROUND_OFF') {
						  // allow negative round-off
						  // keep gst_pct forced to 0 (already set in UI and should be enforced too)
						  $gst_pct = 0.0;
						} else {
						  if ($taxable < 0) $taxable = 0.0;
						}

                        $gst_amt = $taxable * ($gst / 100.0);
                        $line_total = $taxable + $gst_amt;
                  ?>
                    <tr id="prodRow_<?php echo $t; ?>">
                      <td>
                        <input type="hidden" name="rec_status[]" id="rec_status_<?php echo $t; ?>" value="upd">
                        <input type="hidden" name="quote_detail_id[]" id="quote_detail_id_<?php echo $t; ?>" value="<?php echo htmlspecialchars((string)$qid); ?>">
                        <input type="hidden" name="item_id[]" value="<?php echo htmlspecialchars((string)$iid); ?>">
						<input type="hidden" name="item_type[]" id="item_type_<?php echo $t;?>" value="<?php echo htmlspecialchars($r['item_type']); ?>">
                        <input type="text" class="input-md" readonly id="item_name_<?php echo $t; ?>" name="item_name[]" value="<?php echo htmlspecialchars((string)$iname); ?>">

                        <textarea id="item_note_<?php echo $t; ?>" class="form-control form-control-lg" name="item_note[]"
                          <?php if (($allow_remark_item ?? 'Y') === 'N') echo 'style="display:none"'; ?>
                        ><?php echo htmlspecialchars((string)$inote); ?></textarea>
                      </td>

                      <td>
                        <input type="text" class="input-md fld8" readonly maxlength="8" id="hsn_code_<?php echo $t; ?>" name="hsn_code[]" value="<?php echo htmlspecialchars((string)$hsn); ?>">
                      </td>

                      <td>
                        <input type="text" class="input-md fld8" readonly maxlength="8" id="uom_<?php echo $t; ?>" name="uom[]" value="<?php echo htmlspecialchars((string)$uom); ?>">
                      </td>
                      <td>
                        <input type="number" step="0.01" min="0" class="input-md fld12 numr" id="price_<?php echo $t; ?>" name="price[]" value="<?php echo htmlspecialchars((string)$price); ?>">
                      </td>

                      <td>
                        <input type="number" step="0.001" min="0" class="input-md fld12 numr" id="qty_<?php echo $t; ?>" name="qty[]" value="<?php echo htmlspecialchars((string)$qty); ?>">
                      </td>

                      <td>
                          <select class="form-control" style="width:12ch;" name="discount_mode[]" id="discount_mode_<?php echo $t; ?>">
                            <?php
                              $dm = strtoupper((string)$disc_mode);
                              foreach (['NONE','PCT','AMT'] as $opt) {
                                $sel = ($dm === $opt) ? 'selected' : '';
                                echo "<option value=\"$opt\" $sel>$opt</option>";
                              }
                            ?>
                          </select>
					  </td>
					  <td>	
                          <input type="number" step="0.01" min="0" class="form-control numr fld8" name="discount_num[]" id="discount_num_<?php echo $t; ?>"
                                 value="<?php echo htmlspecialchars((string)$disc_num); ?>" title="Discount">
                      </td>

                      <td class="numr"><span id="taxable_<?php echo $t; ?>"><?php echo number_format($taxable, 2, '.', ''); ?></span></td>


                      <td>
                        <input type="number" step="0.01" min="0" class="input-md fld8 numr" id="gst_pct_<?php echo $t; ?>" name="gst_pct[]" value="<?php echo htmlspecialchars((string)$gst); ?>">
                      </td>

                      <td class="numr"><span id="gst_amt_<?php echo $t; ?>"><?php echo number_format($gst_amt, 2, '.', ''); ?></span>
					  
					  <input type="hidden" id="cgst_h_<?= $t ?>" value="0.00">
					  <input type="hidden" id="sgst_h_<?= $t ?>" value="0.00">
					  <input type="hidden" id="igst_h_<?= $t ?>" value="0.00">
					  
					  </td>

                      <td class="numr"><span id="line_total_<?php echo $t; ?>"><?php echo number_format($line_total, 2, '.', ''); ?></span></td>

                      <td>
                        <button type="button" class="btn btn-danger btn-xs" onclick="toggleDeleteRow(<?php echo $t; ?>)">X</button>
                      </td>
                    </tr>
					
					
					
					
                  <?php
                      }
                    }
                  ?>
                </tbody>
				            <tfoot>
              <tr class="totrow">
                <td colspan="7" class="text-right totbox">Totals:</td>
                <td class="totbox"><span id="tot_taxable" style="display:inline-block;text-align:right;padding:2px;">0.00</span></td>
                <td></td>
                <td class="totbox"><span id="tot_gst" style="display:inline-block;text-align:right;padding:2px;">0.00</span></td>
                <td class="totbox"><span id="tot_net" style="display:inline-block;text-align:right;padding:2px;">0.00</span></td>
                <td></td>
              </tr>
              <tr class="totrow">
                <td colspan="12" class="text-right">
                  <span style="margin-right:15px;">CGST: <b><span id="tot_cgst" style="display:inline-block;text-align:right;padding:1px;">0.00</span></b></span>
                  <span style="margin-right:15px;">SGST: <b><span id="tot_sgst" style="display:inline-block;text-align:right;padding:1px;">0.00</span></b></span>
                  <span style="margin-right:15px;">IGST: <b><span id="tot_igst" style="display:inline-block;text-align:right;padding:1px;">0.00</span></b></span>
                </td>
              </tr>
            </tfoot>				
              </table>
            </div>
          </div>
        </div>

        <div style="margin-top:10px;">
          <button name="submit" class="btn btn-primary" type="submit" value="submit">UPDATE</button>
          <a class="btn btn-default" href="<?php echo htmlspecialchars($src_loc); ?>" style="margin-left:6px;">CANCEL</a>
        </div>

      </form>

    </div>
  </main>

  <!-- Party Modal -->
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
                    <button type="button" onclick="searchName()"><span class="glyphicon glyphicon-search" aria-hidden="true"></span></button>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-2"><b>Contact:</b></div>
                  <div class="col-md-8">
                    <input type="text" id="srch_cust_number" name="srch_cust_number" placeholder="Phone Number" value=""/>
                    <button type="button" onclick="searchPhone()"><span class="glyphicon glyphicon-search" aria-hidden="true"></span></button>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-2"><b>Email:</b></div>
                  <div class="col-md-8">
                    <input type="text" id="srch_cust_email" name="srch_cust_email" placeholder="Email" value=""/>
                    <button type="button" onclick="searchEmail()"><span class="glyphicon glyphicon-search" aria-hidden="true"></span></button>
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

  <!-- Item Modal -->
  <div class="modal fade" id="ItemModal" tabindex="-1" role="dialog" aria-labelledby="ItemModalLabel">
    <div class="modal-dialog" role="document">
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
// Rounding off Scripts //
var updatingRoundOff = false;

function findRoundOffT(){
  var found = null;
  $('#js1 tr[id^="prodRow_"]').each(function(){
    var t = this.id.split('_')[1];
	
	// skip deleted rows -
	var rs = String($('#rec_status_' + t).val() || 'upd').toLowerCase();
    if (rs === 'del') return;
	
    var typ = String($('#item_type_' + t).val() || '').toUpperCase();
    if (typ === 'ROUND_OFF') { found = t; return false; }
  });
  return found;
}

function sumNetExcludingRoundOff(){
  var sum = 0;
  $('#js1 tr[id^="prodRow_"]').each(function(){
    var t = this.id.split('_')[1];
	
	var rs = String($('#rec_status_' + t).val() || 'upd').toLowerCase();
    if (rs === 'del') return;
	
    var typ = String($('#item_type_' + t).val() || '').toUpperCase();
    if (typ === 'ROUND_OFF') return;
    var net = parseFloat($('#line_total_' + t).text() || '0');
    if (!isNaN(net)) sum += net;
  });
  return sum;
}

function updateRoundOffIfPresent(){
  if (updatingRoundOff) return;
  var rt = findRoundOffT();
  if (!rt) return;

  updatingRoundOff = true;
  try {
    var baseNet  = sumNetExcludingRoundOff();
    var rounded  = Math.round(baseNet);          // nearest rupee
    var diff     = +(rounded - baseNet).toFixed(2);

    // push values to the roundoff row
    $('#qty_' + rt).val('1');
    $('#gst_pct_' + rt).val('0');
    $('#discount_mode_' + rt).val('NONE');
    $('#discount_num_' + rt).val('0.00');

    // allow negative
    $('#price_' + rt).attr('min', '-999999').val(diff.toFixed(2));

    calcRow(rt);
  } finally {
    updatingRoundOff = false;
  }
}

</script>



<script>
var quoteRowCounter = <?php echo (int)$rowCounter; ?>;
var itemCache = Object.create(null);

var COMP_STATE = <?php echo json_encode((string)($comp_state ?? '')); ?>;

function norm(s){ return String(s||'').trim().toLowerCase(); }
function isLocalTxn(){
  var ps = norm($('#party_state').val());
  var cs = norm(COMP_STATE);
  if (!ps || !cs) return true;
  return ps === cs;
}

function money2(x){
  var v = parseFloat(x);
  if (isNaN(v)) v = 0;
  return (Math.round(v * 100) / 100).toFixed(2);
}

function removeRow(t){
  var el = document.getElementById('prodRow_' + t);
  if (el) el.remove();
  recalcAllTotals();
}

function asNum(v){ v = String(v||'').replace(/,/g,'').trim(); var x = parseFloat(v); return isNaN(x) ? 0 : x; }
function f2(x){ return (Math.round(x*100)/100).toFixed(2); }


function calcRow(t){
  var qty   = asNum($('#qty_'+t).val());
  var price = asNum($('#price_'+t).val());

 var itemType = String($('#item_type_' + t).val() || 'ITEM').toUpperCase();
  if (itemType === 'ROUND_OFF') {
    var taxable = qty * price;      // can be negative
    var gst = 0.00;
    var cgst = 0.00, sgst = 0.00, igst = 0.00;

    $('#taxable_' + t).text(money2(taxable));
    $('#gst_amt_' + t).text('0.00');
    $('#line_total_' + t).text(money2(taxable));

    $('#cgst_h_' + t).val('0.00');
    $('#sgst_h_' + t).val('0.00');
    $('#igst_h_' + t).val('0.00');

    recalcAllTotals();
	updateRoundOffIfPresent();
    return;
  }

  var gstp  = asNum($('#gst_pct_'+t).val());
  var mode  = String($('#discount_mode_'+t).val() || 'NONE').toUpperCase();
  var dnum  = asNum($('#discount_num_'+t).val());
  
  if (qty <= 0) qty = 0;
  if (price < 0) price = 0;

	var disc = 0;
	var dpct = 0;
	var damt = 0;
	
  if (mode === 'PCT') {
		dpct = dnum ;
		if (dpct < 0) dpct = 0;
		if (dpct > 99.99) dpct = 99.99;
		disc = price * (dpct / 100.0);
  } 
  else if (mode === 'AMT') {
		damt = dnum ;
		if (damt < 0) damt = 0;
		disc = damt;
  }
  if (disc < 0) disc = 0;
  if (disc > price) disc = price;

  var final_price = price - disc ;
//  var gross = qty * final_price;

  var taxable = qty * final_price;
  if (taxable < 0) taxable = 0;

  var gstAmt = taxable * (gstp / 100.0);
  var lineTotal = taxable + gstAmt;

  var cgst=0, sgst=0, igst=0;
  if (isLocalTxn()) { cgst = gstAmt/2.0; sgst = gstAmt/2.0; }
  else { igst = gstAmt; }

  $('#taxable_'+t).text(f2(taxable));
  $('#gst_amt_'+t).text(f2(gstAmt));
  $('#line_total_'+t).text(f2(lineTotal));

  // store split (hidden inputs)
  $('#cgst_h_' + t).val(money2(cgst));
  $('#sgst_h_' + t).val(money2(sgst));
  $('#igst_h_' + t).val(money2(igst));

  recalcAllTotals();
  updateRoundOffIfPresent();
  console.log("After Updating Round off");

}

function recalcAllTotals(){
  var totTaxable=0, totGst=0, totNet=0, totC=0, totS=0, totI=0;

  $('#js1 tr[id^="prodRow_"]').each(function(){
		var t = this.id.split('_')[1];
		
		var rs = String($('#rec_status_' + t).val() || 'upd').toLowerCase();
		if (rs === 'del') return; // skips to next row (like continue)

		var taxable = parseFloat($('#taxable_' + t).text() || '0');
		var gst     = parseFloat($('#gst_amt_' + t).text() || '0');
		var net     = parseFloat($('#line_total_' + t).text() || '0');

		var cgst = parseFloat($('#cgst_h_' + t).val() || '0');
		var sgst = parseFloat($('#sgst_h_' + t).val() || '0');
		var igst = parseFloat($('#igst_h_' + t).val() || '0');

		if (!isNaN(taxable)) totTaxable += taxable;
		if (!isNaN(gst))     totGst     += gst;
		if (!isNaN(net))     totNet     += net;

		if (!isNaN(cgst)) totC += cgst;
		if (!isNaN(sgst)) totS += sgst;
		if (!isNaN(igst)) totI += igst;
  });

  $('#tot_taxable').text(money2(totTaxable));
  $('#tot_gst').text(money2(totGst));
  $('#tot_net').text(money2(totNet));
  $('#tot_cgst').text(money2(totC));
  $('#tot_sgst').text(money2(totS));
  $('#tot_igst').text(money2(totI));
   
}

// saleBS-update style: existing rows toggle del/upd; new rows removed
function toggleDeleteRow(t){
  var $row = $('#prodRow_' + t);
  var $rs  = $('#rec_status_' + t);
  if ($row.length === 0 || $rs.length === 0) return;

  var cur = String($rs.val() || 'upd').toLowerCase();

  if (cur === 'new') {
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
  
  recalcAllTotals();
  updateRoundOffIfPresent();
}

function addItemLineRow(it) {
  quoteRowCounter++;
  var t = quoteRowCounter;

  var itemId = it.item_id || '';
  var itemType = String(it.item_type || 'ITEM').toUpperCase();    
  var name   = it.item_name || it.item_disp_name || '';
  var uom    = it.item_uom || '';
  var hsn    = it.hsn_code || '';
  var price  = it.item_pur_price || it.item_sale_price || it.price || '';
  var gst    = it.gst || it.gst_pct || '';

  // prevent duplicates by item_id (same as DC)
  if (itemId) {
    var exists = $('#js1 input[name="item_id[]"]').filter(function(){ return this.value == itemId; }).length;
    if (exists) {
      alert('Item already added. Please change quantity in existing line.');
      return;
    }
  }
  
  var isRoundOff = (itemType === 'ROUND_OFF');
  
  var isCharge = (itemType === 'CHARGE');	


  var $tr = $('<tr/>', { id: 'prodRow_' + t });

  $tr.append($('<td/>').append(
    $('<input/>', { type:'hidden', name:'rec_status[]', id:'rec_status_' + t, value:'new' }),
    $('<input/>', { type:'hidden', name:'quote_detail_id[]', id:'quote_detail_id_' + t, value:'0' }),
    $('<input/>', { type:'hidden', name:'item_id[]', value:itemId }),
	$('<input/>', { type:'hidden', name:'item_type[]', id:'item_type_' + t, value:itemType }),		
    $('<input/>', { type:'text', class:'input-md', readonly:true, id:'item_name_' + t, name:'item_name[]', value:name }),
    $('<textarea/>', {
      id:'item_note_' + t, class:'form-control form-control-lg', name:'item_note[]'
      <?php if (($allow_remark_item ?? 'Y') === 'N') echo ", style:'display:none'"; ?>
    })
  ));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'text', class:'input-md fld8', readonly:true, maxlength:8,
                    id:'hsn_code_' + t, name:'hsn_code[]', value:hsn })
  ));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'text', class:'input-md fld8', readonly:true, maxlength:8, id:'uom_' + t, name:'uom[]', value:uom })
  ));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.01', min:'0', class:'input-md fld12 numr', id:'price_' + t, name:'price[]', value:price })
  ));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.001', min:'0', class:'input-md fld12 numr', id:'qty_' + t, name:'qty[]', value:'1' })
  ));

/*
  var $discTd = $('<td/>');
  var $wrap = $('<div/>', { class:'disc-wrap' });
  $wrap.append(
    $('<select/>', { class:'form-control', style:'width:10ch;', name:'discount_mode[]', id:'discount_mode_' + t })
      .append($('<option/>',{value:'NONE', text:'NONE'}))
      .append($('<option/>',{value:'PCT',  text:'PCT'}))
      .append($('<option/>',{value:'AMT',  text:'AMT'})),
    $('<input/>', { type:'number', step:'0.01', min:'0', class:'form-control numr',
                    name:'discount_pct[]', id:'discount_pct_' + t, value:'0', title:'Discount %' }),
    $('<input/>', { type:'number', step:'0.01', min:'0', class:'form-control numr',
                    name:'discount_amt[]', id:'discount_amt_' + t, value:'0', title:'Discount Amt' })
  );
  $discTd.append($wrap);
  $tr.append($discTd);
*/

  // discount mode
  $tr.append($('<td/>', { class:'disc-mode' }).append(
    $('<select/>', { class:'form-control', name:'discount_mode[]', id:'discount_mode_' + t })
      .append('<option value="NONE">NONE</option>')
      .append('<option value="PCT">PCT</option>')
      .append('<option value="AMT">AMT</option>')
  ));

  // discount values (both posted; server will use mode)
  $tr.append($('<td/>', { class:'disc-val' }).append(
    $('<input/>', { type:'number', step:'0.01', min:'0', class:'input-md fld8', name:'discount_num[]', id:'discount_num_' + t, 
				value:'0.00', title:'Discount'})
  ));


  $tr.append($('<td/>', { class:'numr' }).append($('<span/>', { id:'taxable_' + t }).text('0.00')));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.01', min:'0', class:'input-md fld12 numr',
                    id:'gst_pct_' + t, name:'gst_pct[]', value:gst }),
	$('<input/>', { type:'hidden', id:'cgst_h_' + t, value:'0.00' }),
	$('<input/>', { type:'hidden', id:'sgst_h_' + t, value:'0.00' }),
	$('<input/>', { type:'hidden', id:'igst_h_' + t, value:'0.00' })
  ));
  
  $tr.append($('<td/>', { class:'numr' }).append($('<span/>', { id:'gst_amt_' + t }).text('0.00')));
  $tr.append($('<td/>', { class:'numr' }).append($('<span/>', { id:'line_total_' + t }).text('0.00')));

  $tr.append($('<td/>').append(
    $('<button/>', { type:'button', class:'btn btn-danger btn-xs' })
      .text('X')
      .on('click', function(){ toggleDeleteRow(t); })
  ));


  $('#js1').append($tr);

  if (isRoundOff) {
	  $('#gst_pct_' + t).val('0').prop('readonly', true);
	  $('#discount_mode_' + t).val('NONE').prop('disabled', true);
	  $('#discount_num_' + t).val('0.00').prop('readonly', true);
	  $('#qty_' + t).val('1').prop('readonly', true);
	}

  if (isCharge) {
	  // optional UI hint
	  $('#item_name_' + t).css('font-weight', 'bold');

	  // recommended: no discount on charges
	  $('#discount_mode_' + t).val('NONE').prop('disabled', true);
	  $('#discount_num_' + t).val('0.00').prop('readonly', true);

	  // usually qty is 1 for freight; keep editable if you want cutting charges per qty
	  // $('#quantity_' + t).val('1').prop('readonly', true);
	}


  // Wire events
  $('#price_' + t + ', #qty_' + t + ', #gst_pct_' + t + ', #discount_num_' + t + ', #discount_mode_' + t).on('change blur', function(){
    calcRow(t);
  });
  
  
   
 calcRow(t);
}

function addRoundOffRow(){
	var existingT = findRoundOffT();
  if (existingT) { updateRoundOffIfPresent(); return; }
  var it = {
    item_id: 0,
    item_name: 'Round Off',
    item_uom: 'NOS',
    hsn_code: '',
    item_sale_price: 0,
    gst: 0,
    item_type: 'ROUND_OFF'
  };
  addItemLineRow(it);
  updateRoundOffIfPresent();
}





(function(){
  // Wire recalculation on existing rows
  $('#js1').find('tr[id^="prodRow_"]').each(function(){
    var rid = this.id;
    var t = rid.split('_')[1];
    calcRow(t);

    $('#price_' + t + ', #qty_' + t + ', #gst_pct_' + t + ', #discount_num_' + t ).on('change blur', function(){
      calcRow(t);
    });
    $('#discount_mode_' + t).on('change', function(){
      calcRow(t);
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

  // valid_upto auto fill when blank AND previously auto (UI helper only)
  function autoValidUptoIfBlank(){
    var qdt = $('#voucher_date').val();
    var vu  = $('#valid_upto').val();
    if (!qdt) return;
    if (!vu) {
      var d = new Date(qdt + 'T00:00:00');
      d.setDate(d.getDate() + 15);
      var yyyy = d.getFullYear();
      var mm = String(d.getMonth()+1).padStart(2,'0');
      var dd = String(d.getDate()).padStart(2,'0');
      $('#valid_upto').val(yyyy+'-'+mm+'-'+dd);
    }
  }
  $('#voucher_date').on('change', autoValidUptoIfBlank);
  
  $('#btnAddRoundOff').on('click', function(){ addRoundOffRow(); });

  
})();
</script>

</body>
</html>
