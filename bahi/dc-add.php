<?php
ob_start();
session_start();
include 'include/session.php';
include 'include/param.php';
include 'include/dbo.php' ;
include 'include/item.php' ;
include 'include/stock_journal.php' ;

$debug = 0 ;
/* File Name : dc-add.php

DC Number → Customer Details → Item Details → Dispatch/E-way Details
…and the user can choose Dispatch only / E-way only / Both, with collapsible panels.

This will add record in table_dc_header and table_dc_details 

*/

checksession();
$dtm = getLocalDtm();
$ip_address =  $_SERVER['REMOTE_ADDR'] ;

$login_user = $_SESSION['pos_login'];

$biz_id = $_SESSION['biz_id'] ;	
include 'company-info.php' ;

$dbh = new dbo() ;
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$item_obj = new Item() ; 
$stk_j = new Stock_Journal($dbh);

$doc_type = "DELIVERY CHALLAN" ;
include 'config-doc-entry-info.php' ;   // input ( $biz_id and $doc_type) - output ( $allow_remark_txn ;$allow_remark_item ) ;




/* Voucher Number Generation - Start */
$doc_type = "DELIVERY CHALLAN" ;
$txn_type = "DC" ;
$doc_series_conf = "SELECT * FROM config_doc_prefix WHERE biz_id='$biz_id' and doc_type='$doc_type'" ; 
$stmt = $dbh->query($doc_series_conf) ;
$rec_cnt = $stmt->rowCount() ;
$row = $stmt->fetch() ;
if ($rec_cnt >0 ) {
	$doc_prefix = $row["doc_prefix"] ;
	$len_sno = $row["sno_len"] ;
	$sno_start = $row["sno_start"] ;
	$sno_pad = $row["sno_pad"] ;
}
else
{
	$doc_prefix = "DC-" ;
	$len_sno = 3 ;
	$sno_start = 1 ;
	$sno_pad = 0 ;
}	
if ($debug) echo "<br>:".$doc_prefix.":".$len_sno.":".$sno_start.":".$sno_pad."<br>" ;

$prefix_length = strlen($doc_prefix)+1 ;  // One character after the prefix
$qry = "SELECT SUBSTR(dc_num,$prefix_length)+1 as srl_no from table_dc_header 
        where biz_id=$biz_id and dc_num is not null and dc_num like '$doc_prefix%' ORDER BY dc_id DESC LIMIT 1" ;
$stmt2 = $dbh->query($qry);
$rec_cnt2 = $stmt2->rowCount() ;

if ($rec_cnt2 != 0){
	$row2 = $stmt2->fetch() ;
	$doc_sno=$row2['srl_no'];
}
else               // No record found on this serial number.. first record.	
{
	$doc_sno =$sno_start ;
}	
$doc_num = $doc_prefix. substr(str_repeat($sno_pad, $len_sno) . $doc_sno, -$len_sno);  

/* Voucher Number Generation - End */

    $gstamount = 0;
    $hsn_code = 0;
    $std_rate = 0;
    $final_rate = 0;
    $amount = 0;
    $gst = 0;

//============================================================================
// --- must be inside your existing file after $dbh is ready ---

// $biz_id, $login_user, $dtm, $ip_address, $comp_state are available

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {

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
    if ($ref_order_num === '') $ref_order_dt = null; // don't save date if number empty

    $note = trim((string)($_POST['note'] ?? ''));

    // Shipping
    $diff_ship = isset($_POST['diff_ship']) ? 'Y' : 'N';
    $shp_party_name = $diff_ship === 'Y' ? trim((string)($_POST['party2_name'] ?? '')) : '';
    $shp_address    = $diff_ship === 'Y' ? trim((string)($_POST['party2_address'] ?? '')) : '';
    $shp_state      = $diff_ship === 'Y' ? trim((string)($_POST['party2_state'] ?? '')) : '';
    $shp_pincode    = $diff_ship === 'Y' ? trim((string)($_POST['party2_pincode'] ?? '')) : '';
    $shp_phone      = $diff_ship === 'Y' ? trim((string)($_POST['party2_phone'] ?? '')) : '';
    $shp_gstin      = $diff_ship === 'Y' ? trim((string)($_POST['party2_gstin'] ?? '')) : '';

    // Dispatch mode + dispatch fields (stored in header)
    $dispatch_mode   = strtoupper(trim((string)($_POST['dispatch_mode'] ?? 'NONE'))); // NONE/DISPATCH/EWB/BOTH

    $transport_mode  = trim((string)($_POST['transport_mode'] ?? ''));
    $vehicle_no      = trim((string)($_POST['vehicle_no'] ?? ''));
    $transport_doc_no= trim((string)($_POST['transport_doc_no'] ?? ''));
    $transport_doc_dt= trim((string)($_POST['transport_doc_dt'] ?? ''));
    $transporter_id  = trim((string)($_POST['transporter_id'] ?? ''));
    $transporter_name= trim((string)($_POST['transporter_name'] ?? ''));
    $distance_km     = ($_POST['distance_km'] ?? '') !== '' ? (int)$_POST['distance_km'] : null;
    $place_of_supply = trim((string)($_POST['place_of_supply'] ?? ''));

    // EWB fields (insert into table_ewb; also optionally mirror into header ewb_num/ewb_dt)
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
    // 3) Decide if we should insert EWB
    // -----------------------------
    // Correct rule: only if user chose EWB or BOTH and EWB number is provided.
    $should_insert_ewb = ($ewb_num !== '') && ($dispatch_mode === 'EWB' || $dispatch_mode === 'BOTH');

    // -----------------------------
    // 4) Validate mandatory basics
    // -----------------------------
    if ($dc_num === '' || $dc_dt === '' || $party_state === '') {
        throw new RuntimeException("DC No, DC Date, and Party State are required.");
    }

    if (empty($_POST['item_id']) || !is_array($_POST['item_id'])) {
        throw new RuntimeException("At least one line item is required.");
    }

    // -----------------------------
    // 5) Prepared statements
    // -----------------------------
    $sqlInsertHeader = "
        INSERT INTO table_dc_header (
            biz_id, txn_type, dc_num, dc_dt,
            ref_order_num, ref_order_dt,
            bil_party_id, bil_party_name, bil_address, bil_state, bil_pincode, bil_phone, bil_gstin,
            diff_shp_add, shp_party_name, shp_address, shp_state, shp_pincode, shp_phone, shp_gstin, dispatch_mode,
            transport_mode, vehicle_no, transport_doc_no, transport_doc_dt,
            transporter_id, transporter_name, distance_km, place_of_supply,
            note,
            created_by, created_dtm, created_ip
        )
        VALUES (
            :biz_id, :txn_type, :dc_num, :dc_dt,
            :ref_order_num, :ref_order_dt,
            :bil_party_id, :bil_party_name, :bil_address, :bil_state, :bil_pincode, :bil_phone, :bil_gstin,
            :diff_shp_add, :shp_party_name, :shp_address, :shp_state, :shp_pincode, :shp_phone, :shp_gstin,:dispatch_mode,
            :transport_mode, :vehicle_no, :transport_doc_no, :transport_doc_dt,
            :transporter_id, :transporter_name, :distance_km, :place_of_supply,
            :note,
            :created_by, :created_dtm, :created_ip
        )
    ";

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
        WHERE dc_id = :dc_id
    ";

    $sqlLinkHeaderEwb = "
        UPDATE table_dc_header
        SET ewb_id = :ewb_id, ewb_num = :ewb_num, ewb_dt = :ewb_dt
        WHERE dc_id = :dc_id
    ";

    $stmtHeader = $dbh->prepare($sqlInsertHeader);
    $stmtDetail = $dbh->prepare($sqlInsertDetail);
    $stmtEwb    = $dbh->prepare($sqlInsertEwb);
    $stmtTot    = $dbh->prepare($sqlUpdateHeaderTotals);
    $stmtLink   = $dbh->prepare($sqlLinkHeaderEwb);

    // -----------------------------
    // 6) Execute (transaction style)
    // -----------------------------
    try {
        // MyISAM won't truly rollback; keep this for future InnoDB.
        if ($dbh->inTransaction() === false) {
            $dbh->beginTransaction();
        }

        // 6.1 Header insert
        $stmtHeader->execute([
            ':biz_id'        => $biz_id,
            ':txn_type'      => $txn_type,
            ':dc_num'        => $dc_num,
            ':dc_dt'         => $dc_dt,

            ':ref_order_num' => ($ref_order_num !== '' ? $ref_order_num : null),
            ':ref_order_dt'  => ($ref_order_dt !== '' ? $ref_order_dt : null),

            ':bil_party_id'   => ($party_id !== '' ? $party_id : null),
            ':bil_party_name' => ($party_name !== '' ? $party_name : null),
            ':bil_address'    => ($party_address !== '' ? $party_address : null),
            ':bil_state'      => $party_state, // NOT NULL in schema
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

            ':created_by'     => $login_user,
            ':created_dtm'    => $dtm,
            ':created_ip'     => $ip_address,
        ]);

        $dc_id = (int)$dbh->lastInsertId();

        // 6.2 Detail inserts + totals computation
        $total_taxable = 0.00;
        $total_cgst    = 0.00;
        $total_sgst    = 0.00;
        $total_igst    = 0.00;
        $total_gst     = 0.00;
        $total_net     = 0.00;

        $n = count($_POST['item_id']);

        for ($i = 0; $i < $n; $i++) {

            $item_id    = ($_POST['item_id'][$i] ?? '') !== '' ? (int)$_POST['item_id'][$i] : null;
            $item_name  = trim((string)($_POST['item_name'][$i] ?? ''));
            $uom        = trim((string)($_POST['uom'][$i] ?? ''));
            $hsn        = trim((string)($_POST['hsn_sac'][$i] ?? '')); // your UI uses hsn_sac[]
            $qty        = (float)($_POST['quantity'][$i] ?? 0);
            $price      = (float)($_POST['item_price'][$i] ?? 0);
            $gst_pct    = (float)($_POST['itemGST'][$i] ?? 0);

            // Optional fields (not in UI currently but supported by schema)
            $item_note       = trim((string)($_POST['item_note'][$i] ?? ''));
            $discount_mode   = strtoupper(trim((string)($_POST['discount_mode'][$i] ?? '')));
            $discount_amt    = (float)($_POST['discount_amt'][$i] ?? 0);
            $discount_pct    = ($_POST['discount_pct'][$i] ?? '') !== '' ? (float)$_POST['discount_pct'][$i] : null;
            $ref_order_detid = ($_POST['ref_order_details_id'][$i] ?? '') !== '' ? (int)$_POST['ref_order_details_id'][$i] : null;

            $base = $qty * $price;

            // discount compute
            $disc = 0.00;
            if ($discount_mode === 'PCT' && $discount_pct !== null) {
                $disc = $base * ($discount_pct / 100.0);
            } elseif ($discount_mode === 'AMT') {
                $disc = $discount_amt;
            } else {
                // if mode empty but discount_amt given, treat it as amount
                if ($discount_amt > 0) $disc = $discount_amt;
            }

            if ($disc < 0) $disc = 0.00;
            if ($disc > $base) $disc = $base;

            $taxable = $base - $disc;

            // GST split
            $cgst = 0.00; $sgst = 0.00; $igst = 0.00;

            if ($gst_txn_type === 'local') {
                $cgst = $taxable * ($gst_pct / 200.0);
                $sgst = $taxable * ($gst_pct / 200.0);
            } else {
                $igst = $taxable * ($gst_pct / 100.0);
            }

            $gst_amt = $cgst + $sgst + $igst;
            $line_total = $taxable + $gst_amt;

            // Round to 2 decimals for storage
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

            $stmtDetail->execute([
                ':parent_dc_id'        => $dc_id,
                ':ref_order_details_id'=> $ref_order_detid,
                ':item_srl_no'         => ($i + 1),

                ':item_id'    => $item_id,
                ':item_name'  => ($item_name !== '' ? $item_name : null),
                ':item_note'  => ($item_note !== '' ? $item_note : null),
                ':uom'        => ($uom !== '' ? $uom : null),
                ':qty'        => $qty,
                ':price'      => $price,

                ':discount_mode' => ($discount_mode !== '' ? $discount_mode : null),
                ':discount_amt'  => $discount_amt,
                ':discount_pct'  => $discount_pct,

                ':hsn_code'   => ($hsn !== '' ? $hsn : null),
                ':gst_pct'    => $gst_pct,

                ':taxable_amt'=> $taxable,
                ':cgst_amt'   => $cgst,
                ':sgst_amt'   => $sgst,
                ':igst_amt'   => $igst,
                ':gst_amt'    => $gst_amt,
                ':line_total' => $line_total,
            ]);
        }

        // Header totals update
        $total_taxable = round($total_taxable, 2);
        $total_cgst    = round($total_cgst, 2);
        $total_sgst    = round($total_sgst, 2);
        $total_igst    = round($total_igst, 2);
        $total_gst     = round($total_gst, 2);

        // Choose rounding policy:
        // If you want integer rounding like earlier invoice code:
        // $net_amt = round($total_taxable + $total_gst);
        // Else keep 2 decimals:
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
        ]);

        // 6.3 Optional EWB insert + link to header
        if ($should_insert_ewb) {

            // Minimal validation if inserting EWB
            if ($ewb_dt === '') {
                throw new RuntimeException("EWB Date is required when EWB No is entered.");
            }

            $stmtEwb->execute([
                ':biz_id'        => $biz_id,
                ':txn_type'      => 'DC',
                ':txn_id'        => $dc_id,

                ':ewb_num'       => $ewb_num,
                ':ewb_dt'        => $ewb_dt,
                ':ewb_valid_upto'=> ($ewb_valid_upto !== '' ? $ewb_valid_upto : null),

                // copy dispatch fields into ewb row too (you already keep them in header)
                ':transport_mode'   => ($transport_mode !== '' ? $transport_mode : null),
                ':vehicle_no'       => ($vehicle_no !== '' ? $vehicle_no : null),
                ':transporter_id'   => ($transporter_id !== '' ? $transporter_id : null),
                ':transporter_name' => ($transporter_name !== '' ? $transporter_name : null),
                ':transport_doc_no' => ($transport_doc_no !== '' ? $transport_doc_no : null),
                ':transport_doc_dt' => ($transport_doc_dt !== '' ? $transport_doc_dt : null),
                ':distance_km'      => $distance_km,
                ':place_of_supply'  => ($place_of_supply !== '' ? $place_of_supply : null),

                ':created_by'    => $login_user,
                ':created_dtm'   => $dtm,
                ':created_ip'    => $ip_address,
            ]);

            $ewb_id = (int)$dbh->lastInsertId();

            // Mirror EWB into header as well (optional but you already have columns)
            $stmtLink->execute([
                ':ewb_id' => $ewb_id,
                ':ewb_num'=> $ewb_num,
                ':ewb_dt' => $ewb_dt,
                ':dc_id'  => $dc_id,
            ]);
        }

        if ($dbh->inTransaction()) {
            $dbh->commit();
        }
		$dc_success_msg = "Delivery Challan generated: " . $dc_num;

		echo "<script>
		  alert(" . json_encode($dc_success_msg) . ");
		  window.location.href = 'dc-add.php';
		</script>";
		exit;


        // Success redirect / message
		$dc_success_msg = "Delivery Challan generated: " . $dc_num;

		echo "<script>
		  alert(" . json_encode($dc_success_msg) . ");
		  window.location.href = 'dc-add.php';
		</script>";
		exit;
		
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack(); // MyISAM note: won't rollback table writes, but keep it.
        }
        throw $e; // or show friendly error
    }
}

?>
<!doctype html>
<html lang="en">

<head>
  <title>Delivery Challan Form</title>
  <!-- Required meta tags -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=yes">

  <!-- Bootstrap CSS v5.2.1 -->
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" type="text/css" rel="stylesheet">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>


<style>
  .fld12 { width: 12ch; max-width: 12ch; }
</style>

  
  
  <script type="text/javascript" >

	  function searchName(){
		var biz_id = $('#biz_id').val();
		var cust_name = $('#srch_cust_name').val();
		$.post("party-search-name-ajax.php",
			{p_act_grp:"customer", p_biz_id:biz_id, p_cust_name:cust_name}, function(html)
			{
				$("#searchOutput").html(html).show();
			});
	  }
	  
	  function searchPhone(){
		var biz_id = $('#biz_id').val();
		var phone  = $('#srch_cust_number').val();
		$.post("party-search-contact-ajax.php",
			{p_act_grp:"customer", p_biz_id:biz_id, p_cust_number:phone}, function(html)
			{
				$("#searchOutput").html(html).show();
			});
	  }
	  
	  function searchEmail(){
		var biz_id = $('#biz_id').val();
		var email  = $('#srch_cust_email').val();
		$.post("party-search-email-ajax.php",{p_act_grp:"customer", p_biz_id:biz_id, p_cust_email:email}, function(html)
		{
		  $("#searchOutput").html(html).show();
		});
	  }

function set_party(val){
    var parts = (val||'').split(':');
    $.post('party-info-fetch-ajax.php', {cust_id: parts[0]}, function(resp){
      var obj = JSON.parse(resp||'{}');
      $('#party_id').val(obj.account_id||'');
      $('#party_name').val(obj.account_name||'');
	   document.getElementById("party_name_dup").value = obj.account_name ;	  
      $('#party_address').val(obj.address||'');
      $('#party_phone').val(obj.phone_num||'');
      $('#party_state').val(obj.state||'');
      $('#party_pincode').val(obj.pincode||'');
      $('#party_gstin').val(obj.gstin||'');
      $('#sup_invoice_num').focus();
    });
  }


	
	
	function addParty(){
		var c_name = $("#cst_name").val() ;
		var c_phone = $("#cst_number").val() ;
		var c_add = $("#cst_address").val() ;
		var c_email = $("#cst_email").val() ;
		var c_gstin = $("#cst_gstin").val() ;
		var c_state = $("#cst_state").val() ;
		var c_pincode = $("#cst_pincode").val() ;
		

		alert("Values:"+c_name+":"+c_phone+":"+c_add+":"+c_email+":"+c_gstin+":"+c_state);
		
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
				cst_state: c_state,
				cst_pincode : c_pincode
			},
			success: function (response) {
				set_party(response) ;
			}
	});
	
		return false ;
	}  
 
function set_voucher_numbering_mode(){
	 document.getElementById("manual").checked = true ;
 }

function toggleParty(cb_party_det) {	
	var x = document.getElementById("PartyDetails");
	if (cb_party_det.checked) {
		x.style.display = "block";
	} else {
		x.style.display = "none";
	}

}

function diffShipping(ck_box) {
	var x = document.getElementById("ShipTo");
	if (ck_box.checked) {
		x.style.display = "block";
		$('#btnCopyBillToShip').show();

/*			
	var $p2name = $('#party2_name');

    if ($.trim($p2name.val() || '') === '') {
		}
*/		
	} else {
		x.style.display = "none";
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

 </script>
  
  
</head>

<body style="background-color:#ccf2ff;">
    <!-- place navbar here -->
    <div>
    <?php include 'header.inc.php'; ?>
  </div>
  
  
  <main>
  <div class="container container-md mt-10 p-4">
      <center><h3 class="text-primary" style="margin-top:50px;">Delivery Challan Entry</h3></center> 
    <form method='POST'>
		 		<input hidden id="biz_id" name="biz_id" value="<?php echo $biz_id;?>" type="text">	

      <br>
	  <div class="form-group row">
		<label class="control-label col-md-2" for="voucher_num">Delivery Challan No<span style="color:red">*</span></label>  
		<div class="col-md-3">
			<input name="voucher_num" id="voucher_num" required=required class="input-md" type="text" value="<?php echo $doc_num;?>"
			onchange="set_voucher_numbering_mode()" >
		<br>	<input type="checkbox" name="manual" id="manual">
				<label for="manual">Manual Numbering</label>

		</div>
		<label class="control-label col-md-2" for="voucher_date">Delivery Challan Date<span style="color:red">*</span></label>  
		<div class="col-md-3">
			<input name="voucher_date" id="voucher_date" required=required class="input-md" type="date" value="<?php echo date('Y-m-d'); ?>">
		</div>   
		
	</div>
	  
	<div class="form-group row">
		<label class="control-label col-md-2" for="BillTo"><b>Party Details:</b></label>  
		<div class="col-md-2">
			<button type="button" class="btn bill_btn btn-info " data-toggle="modal" data-target="#PartyModal">Select Party</button>
		</div>
		<label class="control-label col-md-2" for="name">Party ID/Name</label>  
		<div class="col-md-1">
		    <input readonly name="party_id" id="party_id"  class="input-md" type="text" >
		</div>		
		<div class="col-md-2">
			<input readonly name="party_name" id="party_name" class="input-md" type="text" >
		</div>		

		<div class="col-md-3">
			<!--<button id="btn_party_det" name="btn_party_det" class="btn btn-primary btn-block" onClick='toggleParty()'>Show/Hide</button> -->
			Show/Hide Party Details
    		<input   type="checkbox" checked name="cb_party_det" id="cb_party_det" class="input-md" onchange="toggleParty(this)">

		</div>		

	</div>  

    <div ID="PartyDetails" style="display:block;">
	<div class="row" style="margin-bottom:2px;">
		<div ID="BillTO" class="col-md-6">
		Bill To Party:
		<div class="col-md-12">
			<label class="control-label col-md-3" for="name">Party Name </label>  
			<div class="col-md-6">
				<input readonly name="party_name_dup" id="party_name_dup" class="form-control" type="text" >
			</div>		
		</div>		
		<div class="col-md-12">
			<label class="control-label col-md-3" for="party_address">Address</label>  
			<div class="col-md-6">
				<input readonly type="text" name="party_address" id="party_address" class="form-control" >
			</div>
		</div>
	
		<div class="col-md-12">
			<label class="control-label col-md-3" for="party_state">State</label>  
			<div class="col-md-6">
				<input readonly type="text" name="party_state" id="party_state" class="form-control"  >
			</div>
		</div>
	
		<div class="col-md-12">	
			<label class="control-label col-md-3" for="party_pincode">PinCode</label>  
			<div class="col-md-6">
				<input readonly type="text" name="party_pincode" id="party_pincode" class="form-control" >
			</div>
		</div>
		<div class="col-md-12">
			<label class="control-label col-md-3" for="party_gstin">GSTIN</label>  
			<div class="col-md-6">
				<input readonly type="text" name="party_gstin" id="party_gstin" class="form-control" >
			</div>
		</div>
		<div class="col-md-12">
			<label class="control-label col-md-3" for="party_phone">Phone</label>  
			<div class="col-md-6">
				<input readonly type="text" name="party_phone" id="party_phone" class="form-control" >
			</div>
		</div>
	</div> 
	<div  class="col-md-6">
	Different Ship To Address: 
	<input  name="diff_ship" id="diff_ship" class="input-md" type="checkbox" onchange="diffShipping(this)">
	<button type="button" class="btn btn-default btn-xs"
        id="btnCopyBillToShip" style="margin-left:8px; display:none;"
        onclick="copyBillToShip()">
		Copy from Bill-To
	</button>
	
	<div ID="ShipTo" style="display:none;">
		<div class="col-md-12">
			<label class="control-label col-md-4" for="name">Name</label>  
			<div class="col-md-2">
				<input  name="party2_name" id="party2_name" class="input-md" type="text" >
			</div>
		</div>		

		<div class="col-md-12">
			<label class="control-label col-md-4" for="party2_address">Address</label>  
			<div class="col-md-6">
				<input  type="text" name="party2_address" id="party2_address" >
			</div>
		</div>
	
		<div class="col-md-12">
			<label class="control-label col-md-4" for="party2_address_state">State</label>  
			<div class="col-md-2">
				<input  name="party2_state" id="party2_state" class="input-md" type="text" >
			</div>
		</div>
	
		<div class="col-md-12">	
			<label class="control-label col-md-4" for="party2_pincode">PinCode</label>  
			<div class="col-md-2">
				<input  name="party2_pincode" id="party2_pincode" class="input-md" type="text" >
			</div>
		</div>

 
		<div class="col-md-12">
			<label class="control-label col-md-4" for="party2_gstin">GSTIN</label>  
			<div class="col-md-3">
				<input  type="text" name="party2_gstin" id="party2_gstin" class="input-md" >
			</div>
		</div>
		<div class="col-md-12">
			<label class="control-label col-md-4" for="party2_phone">Phone</label>  
			<div class="col-md-3">
				<input  type="text" name="party2_phone" id="party2_phone" class="input-md" >
			</div>
		</div>
	</div>

</div> 


</div>
</div>
 
<div class="row" style="margin-bottom:2px;margin-top:10px;">
<label class="control-label col-md-2" for="sup_doc_num">Order Number</label>  
  <div class="col-md-2">
	<input name="sup_doc_num" id="sup_doc_num" class="input-md" type="text" >
  </div>
    <div class="col-md-1"></div>
	<label class="control-label col-md-2" for="sup_doc_date">Order Date</label>  
	<div class="col-md-3">
		<input type="date" name="sup_doc_date" id="sup_doc_date" class="input-md" disabled>
	</div>
</div>

<div class="row" style="margin-top:10px; <?php if ($allow_remark_txn=='N') echo 'display:none;';?>">
  <div class="col-md-12">
    <label>Remark / Note (DC level)</label>
    <input type="text" class="form-control" name="note" id="note" maxlength="128"
           placeholder="Optional remark to store/print on Delivery Challan">
  </div>
</div>



<div class="card" style="border:1px solid #ddd; border-radius:4px; margin-top:15px;">
  <div class="card-header" style="padding:10px 15px; border-bottom:1px solid #ddd; background:#f5f5f5; font-weight:bold;">
    Line Items
    <button type="button"
            class="btn btn-primary btn-xs pull-right"
            id="btnOpenItemModal"
            data-toggle="modal"
            data-target="#ItemModal">
      Add Item
    </button>
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
        <tbody id="js1"></tbody>
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
        <label class="radio-inline">
          <input type="radio" name="dispatch_mode" value="NONE" checked> None
        </label>
        <label class="radio-inline">
          <input type="radio" name="dispatch_mode" value="DISPATCH"> Dispatch details
        </label>
        <label class="radio-inline">
          <input type="radio" name="dispatch_mode" value="EWB"> E-Way Bill
        </label>
        <label class="radio-inline">
          <input type="radio" name="dispatch_mode" value="BOTH"> Both
        </label>
      </div>
    </div>

    <!-- Dispatch Panel -->
    <div class="panel panel-default" id="panelDispatch" style="display:none;">
      <div class="panel-heading" style="cursor:pointer;" data-toggle="collapse" data-target="#collapseDispatch">
        <h4 class="panel-title">
          Dispatch Details
          <span class="pull-right glyphicon glyphicon-chevron-down"></span>
        </h4>
      </div>
      <div id="collapseDispatch" class="panel-collapse collapse in">
        <div class="panel-body">

          <div class="row">
            <div class="col-md-3">
              <label>Transport Mode</label>
              <select class="form-control" name="transport_mode" id="transport_mode">
                <option value="">-- Select --</option>
                <option value="ROAD">Road</option>
                <option value="RAIL">Rail</option>
                <option value="AIR">Air</option>
                <option value="SHIP">Ship</option>
                <option value="COURIER">Courier</option>
                <option value="HAND">Hand Delivery</option>
              </select>
            </div>

            <div class="col-md-3">
              <label>Vehicle No</label>
              <input type="text" class="form-control" name="vehicle_no" id="vehicle_no" maxlength="20" placeholder="e.g. DL01AB1234">
            </div>

            <div class="col-md-3">
              <label>Transport Doc No (LR/AWB)</label>
              <input type="text" class="form-control" name="transport_doc_no" id="transport_doc_no" maxlength="32" placeholder="LR/AWB No">
            </div>

            <div class="col-md-3">
              <label>Transport Doc Date</label>
              <input type="date" class="form-control" name="transport_doc_dt" id="transport_doc_dt">
            </div>
          </div>

          <div class="row" style="margin-top:10px;">
            <div class="col-md-3">
              <label>Transporter ID</label>
              <input type="text" class="form-control" name="transporter_id" id="transporter_id" maxlength="32">
            </div>

            <div class="col-md-3">
              <label>Transporter Name</label>
              <input type="text" class="form-control" name="transporter_name" id="transporter_name" maxlength="64">
            </div>

            <div class="col-md-3">
              <label>Distance (KM)</label>
              <input type="number" class="form-control" name="distance_km" id="distance_km" min="0" step="1">
            </div>

            <div class="col-md-3">
              <label>Place of Supply</label>
              <input type="text" class="form-control" name="place_of_supply" id="place_of_supply" maxlength="64" placeholder="e.g. Delhi">
            </div>
          </div>

          <p class="help-block" style="margin-top:10px;">
            Tip: For Road transport, Vehicle No is typically required. For Courier/Air, Transport Doc No is more relevant.
          </p>

        </div>
      </div>
    </div>

    <!-- EWB Panel -->
    <div class="panel panel-default" id="panelEwb" style="display:none;">
      <div class="panel-heading" style="cursor:pointer;" data-toggle="collapse" data-target="#collapseEwb">
        <h4 class="panel-title">
          E-Way Bill Details
          <span class="pull-right glyphicon glyphicon-chevron-down"></span>
        </h4>
      </div>
      <div id="collapseEwb" class="panel-collapse collapse in">
        <div class="panel-body">

          <div class="row">
            <div class="col-md-3">
              <label>EWB No</label>
              <input type="text" class="form-control" name="ewb_num" id="ewb_num" maxlength="16" placeholder="12-digit EWB">
            </div>

            <div class="col-md-3">
              <label>EWB Date</label>
              <input type="date" class="form-control" name="ewb_dt" id="ewb_dt">
            </div>

            <div class="col-md-3">
              <label>Valid Upto</label>
              <input type="date" class="form-control" name="ewb_valid_upto" id="ewb_valid_upto">
            </div>


          </div>

          <p class="help-block" style="margin-top:10px;">
            If you enter E-Way Bill, ensure Dispatch details match (vehicle/transporter) if available.
          </p>

        </div>
      </div>
    </div>

  </div>
</div>
<!-- ================= /Dispatch / E-Way Bill ================= -->



<div style="margin-top:10px;">
  <button name="submit" class="btn btn-primary" type="submit" value="submit">SUBMIT</button>
</div>

</div>


</form>

  </div>
 
 </main>
  <footer>
    <!-- place footer here -->
    
  </footer>

<div class="modal fade" id="PartyModal" role="dialog" style="z-index:10000;">
    <div class="modal-dialog modal-md">
      <div class="modal-content">
        <div class="modal-header" style="background:#ed7c65;">
          <button type="button" class="clos" data-dismiss="modal" style="color:red; float:right; font:18px bold; ">X</button>
          <h4 class="modal-title" style="color:#FFFFFF;">Select Party</h4>
        </div>
        <div class="modal-body" style="height:480px;">
        <div class="container-fluid">
  
  <ul class="nav nav-tabs nav-justified" id="mytab">
    <li class="active" style="font-size:18px;"><a data-toggle="tab" href="#log">Search</a></li>
<!--    <li style="font-size:18px;"><a data-toggle="tab" href="#customer_add">Add</a></li> -->
    
  </ul>

  <div class="tab-content" style="margin-top:3px;">
    <div id="log" class="tab-pane fade in active">
	  	<div class="row">
			<div class="col-md-2">
					<b>Name:</b>
			</div>
	  		<div class="col-md-8">
				<input id="srch_cust_name" name="srch_cust_name" placeholder="Name" type="text" value="">
				<button onclick="searchName()"> <span class="glyphicon glyphicon-search" aria-hidden="true"></span> </button>
			</div>
		</div>	

  	<div class="row">
				<div class="col-md-2">
					<b>Contact:</b>
				</div>

			<div class="col-md-8">
				<input type="text"  id="srch_cust_number" name="srch_cust_number"  placeholder="Phone Number" value=""/>
				<button onclick="searchPhone()"> <span class="glyphicon glyphicon-search" aria-hidden="true"></span> </button>
			</div>
	</div>
	
	<div class="row">
				<div class="col-md-2">
					<b>Email:</b>
				</div>

	  		<div class="col-md-8">
	  			<input type="text"  id="srch_cust_email" name="srch_cust_email" placeholder="Email" value=""/>
				<button onClick="searchEmail()"> <span class="glyphicon glyphicon-search" aria-hidden="true"></span> </button>
	  		</div>
	</div>
		
	 <hr>
	 <div id="searchOutput" style="width:auto; height:auto; display:none; z-index:1; border:1px solid gray;"></div>
	</div>
	
<!-- Fields in Customer add tab are names as "cst_" -->	
    <div id="customer_add" class="tab-pane fade" style="margin-left: 20px;">
<!--	  <form method="post" id="customer_add_form">  -->
	<div class="form-group row">
	  	<div class="col-md-6">
			<p><b>Name:</b><input id="cst_name" name="cst_name" placeholder="Name" class="form-control input-md" type="text"></p>
		</div>
		<div class="col-md-6">
			<p><b>Contact:</b> <input type="text"  id="cst_number" name="cst_number" class="form-control"  placeholder="Phone Number" /></p>
		</div>
	</div>
	<div class="form-group row">
		<div class="col-md-12">
	  			<p><b>Address:</b> <input type="text"  id="cst_address" name="cst_address" class="form-control"  placeholder="Address" /></p>
	  		</div>

	</div>	
	<div class="form-group row">
	    <div class="col-md-6">
	  			<p><b>State:</b> 
				   	<select class="form-control" id="cst_state" name="cst_state" required=required>
					<option value="" disabled selected>Choose State</option>
					<?php 
						 for ($i=0;$i<count($list_india_state); $i++)
						 {
							 echo "<option value='$list_india_state[$i]'>$list_india_state[$i]</option>" ;
						 }
					?>
				</select>		
  		</div>

		 <div class="col-md-6">
	  			<p><b>PinCode:</b> <input type="text"  id="cst_pincode" name="cst_pincode" class="form-control"  placeholder="Pin Code" /></p>
	  	</div>
	</div>
	<div class="form-group row">
		<div class="col-md-6">
	  			<p><b>GSTIN:</b> <input type="text"  id="cst_gstin" name="cst_gstin" class="form-control"  placeholder="GSTIN" /></p>
	  	</div>
		<div class="col-md-6">
	  			<p><b>Email:</b> <input type="text"  id="cst_email" name="cst_email" class="form-control"  placeholder="Email" /></p>
	  	</div>

	</div>
	<div class="form-group row">
		<div class="col-md-2"></div>
		<div class="col-md-5">
			<button id="btn_cst_add" name="btn_cst_add" class="btn btn-primary btn-block" onClick='addParty()'>Submit</button>
		</div>
	</div>
<!--	 </form>  -->
    </div>
  </div>
</div>

        </div>
        
      </div>
    </div>
  </div>



<div class="modal fade" id="ItemModal" tabindex="-1" role="dialog" aria-labelledby="ItemModalLabel">
  <div class="modal-dialog" role="document">
    <div class="modal-content">

      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
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






<div class="modal fade" id="BillTo-ShipTo" role="dialog" style="z-index:10000;">
    <div class="modal-dialog modal-md">
      <div class="modal-content">
        <div class="modal-header" style="background:#ed7c65;">
          <button type="button" class="clos" data-dismiss="modal" style="color:red; float:right; font:18px bold; ">X</button>
          <h4 class="modal-title" style="color:#FFFFFF;">Add Party</h4>
        </div>
        <div class="modal-body" style="height:480px;">
        <div class="container-fluid">
  
  <ul class="nav nav-tabs nav-justified" id="mytab">
    <li class="active" style="font-size:18px;"><a data-toggle="tab" href="#log">Search</a></li>
    <li style="font-size:18px;"><a data-toggle="tab" href="#customer_add">Add</a></li>
    
  </ul>

  <div class="tab-content" style="margin-top:3px;">
    <div id="log" class="tab-pane fade in active">
	  	<div class="row">
			<div class="col-md-2">
					<b>Name:</b>
			</div>
	  		<div class="col-md-8">
				<input id="srch_cust_name" name="srch_cust_name" placeholder="Name" type="text" value="">
				<button onclick="searchName()"> <span class="glyphicon glyphicon-search" aria-hidden="true"></span> </button>
			</div>
		</div>	

  	<div class="row">
				<div class="col-md-2">
					<b>Contact:</b>
				</div>

			<div class="col-md-8">
				<input type="text"  id="srch_cust_number" name="srch_cust_number"  placeholder="Phone Number" value=""/>
				<button onclick="searchPhone()"> <span class="glyphicon glyphicon-search" aria-hidden="true"></span> </button>
			</div>
	</div>
	
	<div class="row">
				<div class="col-md-2">
					<b>Email:</b>
				</div>

	  		<div class="col-md-8">
	  			<input type="text"  id="srch_cust_email" name="srch_cust_email" placeholder="Email" value=""/>
				<button onClick="searchEmail()"> <span class="glyphicon glyphicon-search" aria-hidden="true"></span> </button>
	  		</div>
	</div>
		
	 <hr>
	 <div id="searchOutput" style="width:auto; height:auto; display:none; z-index:1; border:1px solid gray;"></div>
	</div>
	
<!-- Fields in Customer add tab are names as "cst_" -->	
    <div id="customer_add" class="tab-pane fade" style="margin-left: 20px;">
<!--	  <form method="post" id="customer_add_form">  -->
	<div class="form-group row">
	  	<div class="col-md-6">
			<p><b>Name:</b><input id="cst_name" name="cst_name" placeholder="Name" class="form-control input-md" type="text"></p>
		</div>
		<div class="col-md-6">
			<p><b>Contact:</b> <input type="text"  id="cst_number" name="cst_number" class="form-control"  placeholder="Phone Number" /></p>
		</div>
	</div>
	<div class="form-group row">
		<div class="col-md-12">
	  			<p><b>Address:</b> <input type="text"  id="cst_address" name="cst_address" class="form-control"  placeholder="Address" /></p>
	  		</div>

	</div>	
	<div class="form-group row">
	    <div class="col-md-6">
	  			<p><b>State:</b> 
				   	<select class="form-control" id="cst_state" name="cst_state" required=required>
					<option value="" disabled selected>Choose State</option>
					<?php 
						 for ($i=0;$i<count($list_india_state); $i++)
						 {
							 echo "<option value='$list_india_state[$i]'>$list_india_state[$i]</option>" ;
						 }
					?>
				</select>		
  		</div>

		 <div class="col-md-6">
	  			<p><b>PinCode:</b> <input type="text"  id="cst_pincode" name="cst_pincode" class="form-control"  placeholder="Pin Code" /></p>
	  	</div>
	</div>
	<div class="form-group row">
		<div class="col-md-6">
	  			<p><b>GSTIN:</b> <input type="text"  id="cst_gstin" name="cst_gstin" class="form-control"  placeholder="GSTIN" /></p>
	  	</div>
		<div class="col-md-6">
	  			<p><b>Email:</b> <input type="text"  id="cst_email" name="cst_email" class="form-control"  placeholder="Email" /></p>
	  	</div>

	</div>
	<div class="form-group row">
		<div class="col-md-2"></div>
		<div class="col-md-5">
			<button id="btn_cst_add" name="btn_cst_add" class="btn btn-primary btn-block" onClick='addParty()'>Submit</button>
		</div>
	</div>
<!--	 </form>  -->
    </div>
  </div>
</div>

        </div>
        
      </div>
    </div>
  </div>

<script>
var poRowCounter = 0;
var itemCache = Object.create(null);

// Hard limit input value length to 12 chars (works for number too)
function limit12(el){
  if (!el) return;
  var v = String(el.value || '');
  if (v.length > 12) el.value = v.slice(0, 12);
}

function showTotal(t) {
  var qtyEl  = document.getElementById("quantity_" + t);
  var rateEl = document.getElementById("item_price_" + t);
  var gstEl  = document.getElementById("itemGST_" + t);

  var qty  = parseFloat((qtyEl && qtyEl.value)  ? qtyEl.value  : "0");
  var rate = parseFloat((rateEl && rateEl.value) ? rateEl.value : "0");
  var gst  = parseFloat((gstEl && gstEl.value)  ? gstEl.value  : "0");

  var sub = qty * rate;
  document.getElementById("itemSubTotal_" + t).innerHTML = (Math.round(sub * 100) / 100).toFixed(2);

  var total = sub + (sub * gst / 100);
  document.getElementById("itemTotal_" + t).innerHTML = (Math.round(total * 100) / 100).toFixed(2);
}

function removeRow(t) {
  var el = document.getElementById("prodRow_" + t);
  if (el) el.remove();
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

  // prevent duplicates by item_id (optional)
  if (itemId) {
    var exists = $('#js1 input[name="item_id[]"]').filter(function(){ return this.value == itemId; }).length;
    if (exists) {
      alert('Item already added. Please change quantity in existing line.');
      return;
    }
  }

  var $tr = $('<tr/>', { id: 'prodRow_' + t });

  $tr.append($('<td/>').append(
    $('<input/>', { type:'hidden', name:'item_id[]', value:itemId }),
    $('<input/>', { type:'text', class:'input-md', readonly:true, id:'item_name_' + t, name:'item_name[]', value:name }),
	$('<textarea/>', {
	  id: 'item_note_' + t,
	  class: 'form-control form-control-lg',
	  name: 'item_note[]'
	  <?php if ($allow_remark_item === 'N') echo ", style: 'display:none'"; ?>
	  
	})
	
  ));

  // HSN/SAC: 12 chars width
  $tr.append($('<td/>').append(
    $('<input/>', {
      type:'text', class:'input-md fld12', readonly:true,
      maxlength:12, size:12,
      id:'hsn_sac_' + t, name:'hsn_sac[]', value:hsn
    })
  ));

  // UoM: 12 chars width
  $tr.append($('<td/>').append(
    $('<input/>', {
      type:'text', class:'input-md fld12', readonly:true,
      maxlength:12, size:12,
      id:'uom_' + t, name:'uom[]', value:uom
    })
  ));

  // Price: 12 chars width + hard trim to 12 on input
  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.01', min:'0', class:'input-md fld12',
                    id:'item_price_' + t, name:'item_price[]', value:price })
  ));

  // Qty: 12 chars width + hard trim to 12 on input
  $tr.append($('<td/>').append(
    $('<input/>', { type:'number', step:'0.001', min:'0', class:'input-md fld12',
                    id:'quantity_' + t, name:'quantity[]', value:'1' })
  ));

  $tr.append($('<td/>').append(
    $('<span/>', { id:'itemSubTotal_' + t }).text('0.00')
  ));

  // GST: 12 chars width + hard trim to 12 on input
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
      .on('click', function(){ removeRow(t); })
  ));

  $('#js1').append($tr);

  // recalc + trim on edits
  $('#item_price_' + t + ', #quantity_' + t + ', #itemGST_' + t).on('input', function(){
    limit12(this);
    showTotal(t);
  });

  showTotal(t);
}

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

  // UX: Enter triggers search, double-click adds
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

    // optional: auto-fill today's date when enabling
    if (!$date.val()) {
      var today = new Date().toISOString().slice(0, 10);
      $date.val(today);
    }
  } else {
    $date.prop('required', false).prop('disabled', true).val('');
  }
}

$(function () {
  togglePIQuoteFields();
  $('#sup_doc_num').on('input', togglePIQuoteFields);
});
</script>

<script>
(function(){
  function setRequired(el, req){
    if (!el) return;
    if (req) $(el).attr('required', 'required');
    else $(el).removeAttr('required');
  }

  function showPanels(mode){
    var showDispatch = (mode === 'DISPATCH' || mode === 'BOTH');
    var showEwb      = (mode === 'EWB' || mode === 'BOTH');

    $('#panelDispatch').toggle(showDispatch);
    $('#panelEwb').toggle(showEwb);

    // Required rules (minimal)
    setRequired('#transport_mode', showDispatch);

    // EWB required
    setRequired('#ewb_num', showEwb);
    setRequired('#ewb_dt', showEwb);

    // Optional: Road => vehicle no required (only if dispatch enabled)
    var tm = ($('#transport_mode').val() || '').toUpperCase();
    var requireVehicle = showDispatch && (tm === 'ROAD');
    setRequired('#vehicle_no', requireVehicle);

    // If dispatch is off, clear required fields (but don't wipe values)
    if (!showDispatch) {
      setRequired('#vehicle_no', false);
      setRequired('#transport_doc_no', false);
      setRequired('#transport_doc_dt', false);
    }
  }

  // On radio change
  $(document).on('change', 'input[name="dispatch_mode"]', function(){
    showPanels($(this).val());
  });

  // Re-evaluate when transport mode changes (Road logic)
  $(document).on('change', '#transport_mode', function(){
    var mode = $('input[name="dispatch_mode"]:checked').val() || 'NONE';
    showPanels(mode);
  });

  // Initialize
  $(function(){
    showPanels($('input[name="dispatch_mode"]:checked').val() || 'NONE');
  });
})();
</script>

<script>
function calcNetAmountFromTable(){
  var net = 0;

  // each row has id="prodRow_t" and line total is in span id="itemTotal_t"
  $('#js1 tr[id^="prodRow_"]').each(function(){
    var rid = this.id;                 // e.g. prodRow_3
    var t = rid.split('_')[1];         // "3"
    var txt = $('#itemTotal_' + t).text() || '0';
    var v = parseFloat(String(txt).replace(/,/g,''));
    if (!isNaN(v)) net += v;
  });

  // round to 2 decimals
  return Math.round(net * 100) / 100;
}

$(function(){
  function openEwbPanel(){
    // select EWB mode
    $('input[name="dispatch_mode"][value="EWB"]').prop('checked', true).trigger('change');

    // ensure panel visible + expanded (Bootstrap collapse)
    $('#panelEwb').show();
    $('#collapseEwb').collapse('show');

    // optional: scroll user to EWB section
    $('html, body').animate({ scrollTop: $('#panelEwb').offset().top - 80 }, 300);

    // optional: focus first field
    $('#ewb_num').focus();
  }

  $('form').on('submit', function(e){
    var net = calcNetAmountFromTable();
	
	// ✅ If EWB number already entered, don't warn
	var ewbNum = $.trim($('#ewb_num').val() || '');
	if (ewbNum !== '') return true;

    if (net > 50000) {
      var msg = "Net amount (including GST) is ₹" + net.toFixed(2) +
                " which is above ₹50,000.\n" +
                "E-Way Bill details may be required.\n\n" +
                "Submit without entering E-Way Bill details?";

      if (!window.confirm(msg)) {
        e.preventDefault();      // stop submit
        openEwbPanel();          // open EWB panel
        return false;
      }
    }

    return true;
  });
});

</script>





</body>
</html>