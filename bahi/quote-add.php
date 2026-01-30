<?php
ob_start();
session_start();

include 'include/session.php';
include 'include/param.php';   // Check usage ??
include 'include/dbo.php';


/*
File Name : quote-add.php

Quote Number → Customer Details → Item Details
(No shipping / dispatch / ewb)

Inserts:
- quote_header
- quote_details
*/

$debug = 0;
checksession();
$dtm        = getLocalDtm();
$ip_address = $_SERVER['REMOTE_ADDR'];

$login_user = $_SESSION['pos_login'];
$biz_id     = (int)$_SESSION['biz_id'];

include 'company-info.php';

$dbh = new dbo();
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// doc config for remarks (optional, if you use same mechanism)
$doc_type = "QUOTE";
include 'config-doc-entry-info.php'; // outputs: $allow_remark_txn, $allow_remark_item

// ------------------------------------------------------------
// Voucher Number Generation (same style as dc-add)
// ------------------------------------------------------------
$doc_type = "QUOTE";
$txn_type = "QUOTE";

$doc_series_conf = "SELECT * FROM config_doc_prefix WHERE biz_id='$biz_id' and doc_type='$doc_type'";
$stmt = $dbh->query($doc_series_conf);
$rec_cnt = $stmt->rowCount();
$row = $stmt->fetch();

if ($rec_cnt > 0) {
    $doc_prefix = $row["doc_prefix"];
    $len_sno    = (int)$row["sno_len"];
    $sno_start  = (int)$row["sno_start"];
    $sno_pad    = (string)$row["sno_pad"];
} else {
    $doc_prefix = "QT-";
    $len_sno    = 4;
    $sno_start  = 1;
    $sno_pad    = "0";
}

$prefix_length = strlen($doc_prefix) + 1; // MySQL SUBSTR is 1-based

$qry = "SELECT CAST(SUBSTR(quote_num,$prefix_length) AS UNSIGNED)+1 as srl_no
        FROM quote_header
        WHERE biz_id=$biz_id
          AND quote_num IS NOT NULL
          AND quote_num LIKE '$doc_prefix%'
        ORDER BY quote_id DESC
        LIMIT 1";
$stmt2 = $dbh->query($qry);
$rec_cnt2 = $stmt2->rowCount();

if ($rec_cnt2 != 0) {
    $row2 = $stmt2->fetch();
    $doc_sno = (int)$row2['srl_no'];
} else {
    $doc_sno = $sno_start;
}
$doc_num = $doc_prefix . substr(str_repeat($sno_pad, $len_sno) . $doc_sno, -$len_sno);


// ------------------------------------------------------------
// POST handler
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {

    // 1) Read inputs
    $quote_num = trim((string)($_POST['voucher_num'] ?? ''));
    $quote_dt  = trim((string)($_POST['voucher_date'] ?? ''));

    $valid_upto = trim((string)($_POST['valid_upto'] ?? ''));

    $party_id      = trim((string)($_POST['party_id'] ?? ''));
    $party_name    = trim((string)($_POST['party_name'] ?? ''));
    $party_address = trim((string)($_POST['party_address'] ?? ''));
    $party_state   = trim((string)($_POST['party_state'] ?? ''));
    $party_pincode = trim((string)($_POST['party_pincode'] ?? ''));
    $party_phone   = trim((string)($_POST['party_phone'] ?? ''));
    $party_email   = trim((string)($_POST['party_email'] ?? ''));
    $party_gstin   = trim((string)($_POST['party_gstin'] ?? ''));

	$note = trim((string)($_POST['note'] ?? ''));

    // 2) GST txn type (local / interstate)
    $gst_txn_type = 'local';
    if ($party_state !== '' && isset($comp_state) && $comp_state !== '') {
        $gst_txn_type = (strcasecmp($comp_state, $party_state) === 0) ? 'local' : 'interstate';
    }

    // 3) Basic validations
    if ($quote_num === '' || $quote_dt === '' || $party_state === '') {
        throw new RuntimeException("Quote No, Quote Date, and Party State are required.");
    }

    if (empty($_POST['item_id']) || !is_array($_POST['item_id'])) {
        throw new RuntimeException("At least one line item is required.");
    }

    // 4) Prepared statements
    $sqlInsertHeader = "
        INSERT INTO quote_header (
            biz_id,
            quote_num, quote_dt,
            party_id, party_name, party_address, party_pincode, party_state, party_gstin,
            party_phone, party_email,
            gst_txn_type,
            note, 
            created_by,
            updated_by,
            quote_status,
            valid_upto
        ) VALUES (
            :biz_id,
            :quote_num, :quote_dt,
            :party_id, :party_name, :party_address, :party_pincode, :party_state, :party_gstin,
            :party_phone, :party_email,
            :gst_txn_type,
            :note, 
            :created_by,
            :updated_by,
            :quote_status,
            :valid_upto
        )
    ";

    $sqlInsertDetail = "
        INSERT INTO quote_details (
            biz_id, parent_quote_id,
            item_id, item_type, item_name, hsn_code, uom,
            qty, price,
            discount_mode, discount_amt, discount_pct,
            taxable_amt,
            gst_pct,
            cgst_amt, sgst_amt, igst_amt,
            gst_amt,
            item_note
        ) VALUES (
            :biz_id, :parent_quote_id,
            :item_id, :item_type, :item_name, :hsn_code, :uom,
            :qty, :price,
            :discount_mode, :discount_amt, :discount_pct,
            :taxable_amt,
            :gst_pct,
            :cgst_amt, :sgst_amt, :igst_amt,
            :gst_amt,
            :item_note
        )
    ";

    $stmtHeader = $dbh->prepare($sqlInsertHeader);
    $stmtDetail = $dbh->prepare($sqlInsertDetail);

    try {
        if (!$dbh->inTransaction()) $dbh->beginTransaction();

        // 4.1 Header insert
        $stmtHeader->execute([
            ':biz_id'      => $biz_id,

            ':quote_num'   => $quote_num,
            ':quote_dt'    => $quote_dt,

            ':party_id'      => ($party_id !== '' ? (int)$party_id : null),
            ':party_name'    => ($party_name !== '' ? $party_name : null),
            ':party_address' => ($party_address !== '' ? $party_address : null),
            ':party_pincode' => ($party_pincode !== '' ? $party_pincode : null),
            ':party_state'   => $party_state,
            ':party_gstin'   => ($party_gstin !== '' ? $party_gstin : null),

            ':party_phone' => ($party_phone !== '' ? $party_phone : null),
            ':party_email' => ($party_email !== '' ? $party_email : null),

            ':gst_txn_type' => $gst_txn_type,

            ':note' => ($note !== '' ? $note : null),

            ':created_by'  => $login_user,
            ':updated_by'  => null,

            ':quote_status' => 'SENT',
            ':valid_upto'   => ($valid_upto !== '' ? $valid_upto : null),
        ]);

        $quote_id = (int)$dbh->lastInsertId();

        // 4.2 Details insert (server recompute amounts)
        $n = count($_POST['item_id']);

        for ($i = 0; $i < $n; $i++) {

            $item_id   = ($_POST['item_id'][$i] ?? '') !== '' ? (int)$_POST['item_id'][$i] : null;
			
			$item_type = strtoupper(trim((string)($_POST['item_type'][$i] ?? 'ITEM')));
			
            $item_name = trim((string)($_POST['item_name'][$i] ?? ''));
            $uom       = trim((string)($_POST['uom'][$i] ?? ''));
            $hsn       = trim((string)($_POST['hsn_sac'][$i] ?? ''));
            $qty       = (float)($_POST['quantity'][$i] ?? 0);
            $price     = (float)($_POST['item_price'][$i] ?? 0);
            $gst_pct   = (float)($_POST['itemGST'][$i] ?? 0);

            $item_note = trim((string)($_POST['item_note'][$i] ?? ''));

            $discount_mode = strtoupper(trim((string)($_POST['discount_mode'][$i] ?? 'NONE')));
            if (!in_array($discount_mode, ['NONE','PCT','AMT'], true)) $discount_mode = 'NONE';

            $discount_num = (float)($_POST['discount_num'][$i] ?? 0);  // Same field is used for amount and pct.
            // $discount_pct = (float)($_POST['discount_pct'][$i] ?? 0);

            // per-row validation

			if ($item_id === null) continue;
			if ($qty <= 0) continue;
			if ($item_type !== 'ROUND_OFF' && $price < 0) continue;


            // discount
			$discount_amt = 0 ;
			$discount_pct = 0 ;
            $disc = 0.00;
			
            if ($discount_mode === 'PCT') {
                $disc = $price * ($discount_num / 100.0);
				$discount_pct = $discount_num ;
            } elseif ($discount_mode === 'AMT') {
                $disc = $discount_num;
				$discount_amt = $discount_num ; 
            }
			
            if ($disc < 0) $disc = 0.00;
            if ($disc > $price) $disc = $price;
			
			$final_price = $price - $disc ;

            $taxable = $qty * $final_price;

            // GST split
            $cgst = 0.00; $sgst = 0.00; $igst = 0.00;
            if ($gst_txn_type === 'local') {
                $cgst = $taxable * ($gst_pct / 200.0);
                $sgst = $taxable * ($gst_pct / 200.0);
            } else {
                $igst = $taxable * ($gst_pct / 100.0);
            }
            $gst_amt = $cgst + $sgst + $igst;

            // round (money)
            $taxable = round($taxable, 2);
            $cgst    = round($cgst, 2);
            $sgst    = round($sgst, 2);
            $igst    = round($igst, 2);
            $gst_amt = round($gst_amt, 2);

			if ($item_type === 'ROUND_OFF') {
			  $gst_pct = 0.00;
			  $discount_mode = 'NONE';
			  $discount_amt = 0.00;
			  $discount_pct = 0.00;

			  // taxable is the roundoff itself (qty should be 1)
			  $taxable = $qty * $price;

			  $cgst = $sgst = $igst = $gst_amt = 0.00;

			  $taxable = round($taxable, 2);
			}


            $stmtDetail->execute([
                ':biz_id'          => $biz_id,
                ':parent_quote_id' => $quote_id,

                ':item_id'   => $item_id,
				':item_type'       => $item_type,
                ':item_name' => ($item_name !== '' ? $item_name : null),
                ':hsn_code'  => ($hsn !== '' ? $hsn : null),
                ':uom'       => ($uom !== '' ? $uom : null),

                ':qty'   => $qty,
                ':price' => $price,

                ':discount_mode' => $discount_mode,
                ':discount_amt'  => round($discount_amt, 2),
                ':discount_pct'  => round($discount_pct, 2),

                ':taxable_amt' => $taxable,
                ':gst_pct'     => $gst_pct,

                ':cgst_amt' => $cgst,
                ':sgst_amt' => $sgst,
                ':igst_amt' => $igst,

                ':gst_amt'  => $gst_amt,

                ':item_note' => ($item_note !== '' ? $item_note : null),
            ]);
        }

        if ($dbh->inTransaction()) $dbh->commit();

        $msg = "Quote generated: " . $quote_num;
        echo "<script>
          alert(" . json_encode($msg) . ");
          window.location.href = 'quote-add.php';
        </script>";
        exit;

    } catch (Throwable $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        throw $e;
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <title>Quote Entry</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=yes">

  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

  <style>
    body { background-color:#ccf2ff; }
    .fld12 { width: 12ch; max-width: 12ch; }
    .totbox { font-weight:bold; }
    .totrow td { background:#f5f5f5; }
	
	.fld8 { width: 8ch; max-width: 8ch; }
	
	td.disc-mode { min-width: 90px; }
	td.disc-val  { min-width: 90px; }
	td.disc-mode select { height: 30px; padding: 4px 6px; }
	
  </style>

<script>
// PARTY SEARCH (same as DC)
function searchName(){
  var biz_id = $('#biz_id').val();
  var cust_name = $('#srch_cust_name').val();
  $.post("party-search-name-ajax.php",
    {p_act_grp:"customer", p_biz_id:biz_id, p_cust_name:cust_name},
    function(html){ $("#searchOutput").html(html).show(); }
  );
}
function searchPhone(){
  var biz_id = $('#biz_id').val();
  var phone  = $('#srch_cust_number').val();
  $.post("party-search-contact-ajax.php",
    {p_act_grp:"customer", p_biz_id:biz_id, p_cust_number:phone},
    function(html){ $("#searchOutput").html(html).show(); }
  );
}
function searchEmail(){
  var biz_id = $('#biz_id').val();
  var email  = $('#srch_cust_email').val();
  $.post("party-search-email-ajax.php",
    {p_act_grp:"customer", p_biz_id:biz_id, p_cust_email:email},
    function(html){ $("#searchOutput").html(html).show(); }
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
    $('#party_phone').val(obj.phone_num||'');
    $('#party_email').val(obj.email||'');
    $('#party_state').val(obj.state||'');
    $('#party_pincode').val(obj.pincode||'');
    $('#party_gstin').val(obj.gstin||'');
    recalcAllTotals();
  });
}

function toggleParty(cb){
  var x = document.getElementById("PartyDetails");
  x.style.display = cb.checked ? "block" : "none";
}
</script>

</head>

<body>
<div><?php include 'header.inc.php'; ?></div>

<main>
<div class="container container-md mt-10 p-4">
  <center><h3 class="text-primary" style="margin-top:50px;">Quote Entry</h3></center>

  <form method="POST">
    <input type="hidden" id="biz_id" name="biz_id" value="<?php echo (int)$biz_id; ?>">

    <div class="form-group row" style="margin-top:15px;">
      <label class="control-label col-md-2">Quote No<span style="color:red">*</span></label>
      <div class="col-md-2">
        <input name="voucher_num" id="voucher_num" required class="input-md" type="text"
               value="<?php echo htmlspecialchars($doc_num); ?>">
        <br>
        <input type="checkbox" name="manual" id="manual">
        <label for="manual">Manual Numbering</label>
      </div>

      <label class="control-label col-md-2">Quote Date<span style="color:red">*</span></label>
      <div class="col-md-2">
        <input name="voucher_date" id="voucher_date" required class="input-md" type="date"
               value="<?php echo date('Y-m-d'); ?>">
      </div>
	  <label class="control-label col-md-2">Valid Upto</label>
      <div class="col-md-2">
        <input name="valid_upto" id="valid_upto" class="input-md" type="date">
		<input type="hidden" id="valid_upto_manual" name="valid_upto_manual" value="0">
      </div>
    </div>


    <div class="form-group row" style="margin-top:10px;">
      <label class="control-label col-md-2"><b>Party Details:</b></label>
      <div class="col-md-2">
        <button type="button" class="btn btn-info" data-toggle="modal" data-target="#PartyModal">Select Party</button>
      </div>
      <label class="control-label col-md-2">Party ID/Name</label>
      <div class="col-md-1">
        <input readonly name="party_id" id="party_id" class="input-md" type="text">
      </div>
      <div class="col-md-2">
        <input readonly name="party_name" id="party_name" class="input-md" type="text">
      </div>
      <div class="col-md-3">
        Show/Hide Party Details
        <input type="checkbox" checked name="cb_party_det" id="cb_party_det" class="input-md"
               onchange="toggleParty(this)">
      </div>
    </div>

    <div id="PartyDetails" style="display:block;">
      <div class="row">
        <div class="col-md-6">
          <div class="col-md-12">
            <label class="control-label col-md-3">Party Name</label>
            <div class="col-md-6">
              <input readonly name="party_name_dup" id="party_name_dup" class="form-control" type="text">
            </div>
          </div>

          <div class="col-md-12">
            <label class="control-label col-md-3">Address</label>
            <div class="col-md-6">
              <input type="text" name="party_address" id="party_address" class="form-control">
            </div>
          </div>

          <div class="col-md-12">
            <label class="control-label col-md-3">State<span style="color:red">*</span></label>
            <div class="col-md-6">
              <input readonly type="text" name="party_state" id="party_state" class="form-control">
            </div>
          </div>

          <div class="col-md-12">
            <label class="control-label col-md-3">PinCode</label>
            <div class="col-md-6">
              <input readonly type="text" name="party_pincode" id="party_pincode" class="form-control">
            </div>
          </div>

        </div>

        <div class="col-md-6">
          <p class="help-block">
            GST type will auto-decide based on Party State vs Company State (<?php echo htmlspecialchars($comp_state ?? ''); ?>).
          </p>
		  
		            <div class="col-md-12">
            <label class="control-label col-md-3">GSTIN</label>
            <div class="col-md-6">
              <input  type="text" name="party_gstin" id="party_gstin" class="form-control">
            </div>
          </div>

          <div class="col-md-12">
            <label class="control-label col-md-3">Phone</label>
            <div class="col-md-6">
              <input type="text" name="party_phone" id="party_phone" class="form-control">
            </div>
          </div>

          <div class="col-md-12">
            <label class="control-label col-md-3">Email</label>
            <div class="col-md-6">
              <input type="text" name="party_email" id="party_email" class="form-control">
            </div>
          </div>

        </div>
      </div>
    </div>


	<div class="row" style="margin-top:10px; <?php if ($allow_remark_txn=='N') echo 'display:none;';?>">
	  <div class="col-md-12">
		<label>Remark / Note (Quote/PI level)</label>
		<input type="text" class="form-control" name="note" id="note" maxlength="128"
			   placeholder="Optional remark to store on Quote/PI">
	  </div>
	</div>

    <!-- Line Items -->
    <div class="card" style="border:1px solid #ddd; border-radius:4px; margin-top:15px;">
      <div class="card-header" style="padding:10px 15px; border-bottom:1px solid #ddd; background:#f5f5f5; font-weight:bold;">
        Line Items
        <button type="button" class="btn btn-primary btn-xs pull-right"
                id="btnOpenItemModal" data-toggle="modal" data-target="#ItemModal">
          Add Item
        </button>
     

		<button type="button" class="btn btn-warning btn-xs pull-right"
				id="btnAddRoundOff" style="margin-right:8px;">
		  Add Round Off
		</button>

 </div>

      <div class="card-body" style="padding:0;">
        <div class="table-responsive">
          <table class="table table-hover" style="margin:0;">
            <thead>
              <tr>
                <th>Name</th>
                <th>HSN/SAC</th>
                <th>UoM</th>
                <th>Price</th>
                <th>Qty</th>
                <th>Disc Mode</th>
                <th>Disc</th>
                <th>Taxable</th>
                <th>GST%</th>
                <th>GST</th>
                <th>Line Total</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="js1"></tbody>
            <tfoot>
              <tr class="totrow">
                <td colspan="7" class="text-right totbox">Totals:</td>
                <td class="totbox"><span id="tot_taxable" style="display:inline-block;text-align:right;padding:1px;">0.00</span></td>
                <td></td>
                <td class="totbox"><span id="tot_gst" style="display:inline-block;text-align:right;padding:1px;">0.00</span></td>
                <td class="totbox"><span id="tot_net" style="display:inline-block;text-align:right;padding:1px;">0.00</span></td>
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
      <button name="submit" class="btn btn-primary" type="submit" value="submit">SUBMIT</button>
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
          <ul class="nav nav-tabs nav-justified" id="partyTab">
            <li class="active" style="font-size:18px;"><a data-toggle="tab" href="#party_search">Search</a></li>
          </ul>

          <div class="tab-content" style="margin-top:3px;">
            <div id="party_search" class="tab-pane fade in active">
              <div class="row">
                <div class="col-md-2"><b>Name:</b></div>
                <div class="col-md-8">
                  <input id="srch_cust_name" placeholder="Name" type="text" value="">
                  <button type="button" onclick="searchName()">
                    <span class="glyphicon glyphicon-search"></span>
                  </button>
                </div>
              </div>

              <div class="row">
                <div class="col-md-2"><b>Contact:</b></div>
                <div class="col-md-8">
                  <input type="text" id="srch_cust_number" placeholder="Phone Number" value=""/>
                  <button type="button" onclick="searchPhone()">
                    <span class="glyphicon glyphicon-search"></span>
                  </button>
                </div>
              </div>

              <div class="row">
                <div class="col-md-2"><b>Email:</b></div>
                <div class="col-md-8">
                  <input type="text" id="srch_cust_email" placeholder="Email" value=""/>
                  <button type="button" onclick="searchEmail()">
                    <span class="glyphicon glyphicon-search"></span>
                  </button>
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
<script>
// Rounding off Scripts //
var updatingRoundOff = false;

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
    var net = parseFloat($('#linetotal_' + t).text() || '0');
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
    $('#quantity_' + rt).val('1');
    $('#itemGST_' + rt).val('0');
    $('#discount_mode_' + rt).val('NONE');
    $('#discount_num_' + rt).val('0.00');

    // allow negative
    $('#item_price_' + rt).attr('min', '-999999').val(diff.toFixed(2));

    recalcRow(rt);
  } finally {
    updatingRoundOff = false;
  }
}



</script>


<script>
(function(){
  function addDays(yyyy_mm_dd, days){
    if (!yyyy_mm_dd) return '';
    var parts = yyyy_mm_dd.split('-');
    if (parts.length !== 3) return '';
    var d = new Date(parts[0], parseInt(parts[1],10)-1, parts[2]);
    d.setDate(d.getDate() + days);
    var mm = String(d.getMonth()+1).padStart(2,'0');
    var dd = String(d.getDate()).padStart(2,'0');
    return d.getFullYear() + '-' + mm + '-' + dd;
  }

  // if user edits valid_upto, lock it (manual override)
  $('#valid_upto').on('input change', function(){
    $('#valid_upto_manual').val('1');
  });

  // on quote date change -> set valid upto = quote_dt + 15 (only if not manual)
  $('#voucher_date').on('change', function(){
    var qdt = $(this).val();
    if (!qdt) return;

    var manual = ($('#valid_upto_manual').val() || '0') === '1';
    if (!manual) {
      $('#valid_upto').val(addDays(qdt, 15));
    }
  });

  // initial default on page load (if empty and not manual)
  $(function(){
    var qdt = $('#voucher_date').val();
    var vu  = $('#valid_upto').val();
    var manual = ($('#valid_upto_manual').val() || '0') === '1';
    if (qdt && !vu && !manual) {
      $('#valid_upto').val(addDays(qdt, 15));
    }
  });
})();
</script>



<script>
var quoteRowCounter = 0;
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

function recalcRow(t){
  var qty  = parseFloat($('#quantity_' + t).val() || '0');
  var rate = parseFloat($('#item_price_' + t).val() || '0');

 var itemType = String($('#item_type_' + t).val() || 'ITEM').toUpperCase();
  if (itemType === 'ROUND_OFF') {
    var taxable = qty * rate;      // can be negative
    var gst = 0.00;
    var cgst = 0.00, sgst = 0.00, igst = 0.00;

    $('#taxable_' + t).text(money2(taxable));
    $('#gstamt_' + t).text('0.00');
    $('#linetotal_' + t).text(money2(taxable));

    $('#cgst_h_' + t).val('0.00');
    $('#sgst_h_' + t).val('0.00');
    $('#igst_h_' + t).val('0.00');

    recalcAllTotals();
    return;
  }

  var gstp = parseFloat($('#itemGST_' + t).val() || '0');
  var mode = String($('#discount_mode_' + t).val() || 'NONE').toUpperCase();
  var dnum = parseFloat($('#discount_num_' + t).val() || '0');

  var disc = 0;
  if (mode === 'PCT') disc = rate * (dnum / 100.0);
  else if (mode === 'AMT') disc = dnum;

  if (disc < 0) disc = 0;
  if (disc > rate) disc = rate;
  var final_price = rate - disc ;

  var taxable = qty * final_price;
  var gstAmt = taxable * (gstp / 100.0);

  var cgst=0, sgst=0, igst=0;
  if (isLocalTxn()) { cgst = gstAmt/2.0; sgst = gstAmt/2.0; }
  else { igst = gstAmt; }

  $('#taxable_' + t).text(money2(taxable));
  $('#gstamt_' + t).text(money2(gstAmt));
  $('#linetotal_' + t).text(money2(taxable + gstAmt));

  // store split (hidden inputs)
  $('#cgst_h_' + t).val(money2(cgst));
  $('#sgst_h_' + t).val(money2(sgst));
  $('#igst_h_' + t).val(money2(igst));

  recalcAllTotals();
  updateRoundOffIfPresent();
}

function recalcAllTotals(){
  var totTaxable=0, totGst=0, totNet=0, totC=0, totS=0, totI=0;

  $('#js1 tr[id^="prodRow_"]').each(function(){
    var t = this.id.split('_')[1];

    var taxable = parseFloat($('#taxable_' + t).text() || '0');
    var gst     = parseFloat($('#gstamt_' + t).text() || '0');
    var net     = parseFloat($('#linetotal_' + t).text() || '0');

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

function addItemLineRow(it){
  quoteRowCounter++;
  var t = quoteRowCounter;

  var numInStyle  = 'text-align:right;padding:1px;';
  var numOutStyle = 'display:block;text-align:right;padding:1px;'; // for spans inside <td>

  var itemId = (it.item_id ?? '');
  var itemType = String(it.item_type || 'ITEM').toUpperCase();  
  var name   = it.item_name || it.item_disp_name || '';
  var uom    = it.item_uom || '';
  var hsn    = it.hsn_code || '';
  var price  = (it.item_sale_price ?? it.item_pur_price ?? '');
  var gst    = it.gst || '';

  // prevent duplicates by item_id (optional)
  var itemIdStr = String(itemId ?? '').trim();   // itemId can be 0
  if (itemIdStr !== '') {
    var exists = $('#js1 input[name="item_id[]"]').filter(function(){ return this.value == itemId; }).length;
    if (exists) { alert('Item already added. Change qty in existing line.'); return; }
  }

  var isRoundOff = (itemType === 'ROUND_OFF');
	
  var isCharge = (itemType === 'CHARGE');	

  var $tr = $('<tr/>', { id:'prodRow_' + t });

  // name + hidden
  $tr.append($('<td/>').append(
    $('<input/>', { type:'hidden', name:'item_id[]', value:itemId }),
	$('<input/>', { type:'hidden', name:'item_type[]', id:'item_type_' + t, value:itemType }),	
    $('<input/>', { type:'text', class:'input-md', readonly:true, name:'item_name[]', id:'item_name_' + t, value:name }),
    $('<textarea/>', { class:'form-control', name:'item_note[]', id:'item_note_' + t, placeholder:'Item note (optional)' })
      <?php if (($allow_remark_item ?? 'Y')=='N') echo ".css('display','none')"; ?> 
  ));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'text', class:'input-md fld8', readonly:true, maxlength:8, name:'hsn_sac[]', id:'hsn_sac_' + t, value:hsn })
  ));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'text', class:'input-md fld8', readonly:true, maxlength:8, name:'uom[]', id:'uom_' + t, value:uom })
  ));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.01', min:(isRoundOff ? '-999999' : '0'), 
				class:'input-md fld12', name:'item_price[]', id:'item_price_' + t, value:price, style: numInStyle })
  ));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.001', min:'0', class:'input-md fld12', name:'quantity[]', id:'quantity_' + t, value:'1' , style: numInStyle})
  ));

  // discount mode
  $tr.append($('<td/>', { class:'disc-mode' }).append(
    $('<select/>', { class:'form-control', name:'discount_mode[]', id:'discount_mode_' + t })
      .append('<option value="NONE">NONE</option>')
      .append('<option value="PCT">PCT</option>')
      .append('<option value="AMT">AMT</option>')
  ));

  // discount values (both posted; server will use mode)
  $tr.append($('<td/>', { class:'disc-val' }).append(
    $('<input/>', { type:'number', step:'0.01', min:'0', class:'input-md fld8', name:'discount_num[]', id:'discount_num_' + t, value:'0.00', placeholder:'Amt', style: numInStyle })
  ));

  $tr.append($('<td/>').append($('<span/>', { id:'taxable_' + t ,  style: numOutStyle  }).text('0.00')));

  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.01', min:'0', class:'input-md fld8', name:'itemGST[]', id:'itemGST_' + t, value:gst, style: numInStyle })
  ));

  $tr.append($('<td/>').append($('<span/>', { id:'gstamt_' + t,  style: numOutStyle  }).text('0.00')));

  $tr.append($('<td/>').append($('<span/>', { id:'linetotal_' + t ,  style: numOutStyle  }).text('0.00')));

  $tr.append($('<td/>').append(
    $('<button/>', { type:'button', class:'btn btn-danger btn-xs' }).text('X').on('click', function(){ removeRow(t); })
  ));

  // hidden split holders
  $tr.append($('<input/>', { type:'hidden', id:'cgst_h_' + t, value:'0.00' }));
  $tr.append($('<input/>', { type:'hidden', id:'sgst_h_' + t, value:'0.00' }));
  $tr.append($('<input/>', { type:'hidden', id:'igst_h_' + t, value:'0.00' }));

  $('#js1').append($tr);
  
  if (isRoundOff) {
	  $('#itemGST_' + t).val('0').prop('readonly', true);
	  $('#discount_mode_' + t).val('NONE').prop('disabled', true);
	  $('#discount_num_' + t).val('0.00').prop('readonly', true);
	  $('#quantity_' + t).val('1').prop('readonly', true);
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


  // bind changes
  $('#item_price_' + t + ', #quantity_' + t + ', #itemGST_' + t + ', #discount_mode_' + t + ', #discount_num_' + t )
    .on('input change', function(){ recalcRow(t); });

  recalcRow(t);
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


// Item search modal logic
(function(){
  $('#ItemModal').on('shown.bs.modal', function () {
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
      url: 'dc-item-searched-list-ajax.php', // using delivey challan item search 
      method: 'POST',
      dataType: 'json',
      data: { biz_id: biz_id, q: q }
    }).done(function(resp){
      if (!resp || !resp.ok) { $help.text((resp && resp.msg) ? resp.msg : 'Search failed.').show(); return; }
      if (!resp.items || !resp.items.length) { $help.text('No items found for "' + q + '".').show(); return; }

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

// Submit guard: ensure at least 1 item
$(function(){
	$('form').on('submit', function(e){
	  var realCnt = 0;
	  $('#js1 tr[id^="prodRow_"]').each(function(){
		var t = this.id.split('_')[1];
		var typ = String($('#item_type_' + t).val() || '').toUpperCase();
		if (typ !== 'ROUND_OFF') realCnt++;
	  });

	  if (realCnt <= 0) {
		alert('Please add at least one item (not only Round Off).');
		e.preventDefault();
		return false;
	  }

	  updateRoundOffIfPresent(); // ensure latest before submit
	  return true;
	});  

  $('#btnAddRoundOff').on('click', function(){
    addRoundOffRow();
  });
  
});
</script>

</body>
</html>
