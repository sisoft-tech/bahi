<?php
ob_start();
session_start();
include 'include/param.php';
include 'include/dbo.php' ;
include 'include/session.php';

include 'include/item.php' ;
include 'include/stock_journal.php' ;
include 'include/ledger_journal.php' ;


/* 
21-May-2024 : Removed readonly for Party Name, Address, Phone, PINCODE, GSTIN etc. 
              However change of state not permitted as of now
07-Jun-2024 : Removed mandatory for Phone, Pincode, GSTIN
              Mandatory: Party ID, Party Name, Address, State(ReadOnly) .
10-Jun-2024 : Voucher Numbering changed, should take changed voucher number only. Added new check box (manual numbering), if checked, 
              entered voucher number is added in the table 	
12-Jun-2024 : Use Voucher number configuration if available.			  
15-Mar-2025: Added logic for Addtional charge (item type) -(Freight, Cutting Charge etc. GST will come from product group)
31-Mar-2025: Bill to Ship Logic Added Here -
12-Oct-2025:
Journal Entry at the time of Sale
Debit 	: Accounts Receivable / Customer A/c
Credit 	: Sales A/c, Output GST / TAX A/c (if applicable)
*/


$debug = 0 ;

$sales_list_url = 'bill-manage.php';

checksession();
$biz_id = $_SESSION['biz_id'] ;	
$login_user = $_SESSION['pos_login'];

$txn_type = "SALES" ;
$doc_type = "SALES" ;

include 'company-info.php' ;
include 'config-doc-entry-info.php' ;   // input ( $biz_id and $doc_type) - output ( $allow_remark_txn ;$allow_remark_item ) ;

$dbh = new dbo() ;
$item_obj = new Item() ; 
$stk_j = new Stock_Journal($dbh);
$dtm = getLocalDtm();


$src_loc="pos-index" ;

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
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
	if (!empty($_SESSION['sale_bs_add_success']) && is_array($_SESSION['sale_bs_add_success'])) {
		$success_flash = $_SESSION['sale_bs_add_success'];
		unset($_SESSION['sale_bs_add_success']);
	}
	
	$doc_num = nextDocNumber($dbh, $biz_id, $txn_type);

    $gstamount = 0;
    $hsn_code = 0;
    $std_rate = 0;
    $final_rate = 0;
    $amount = 0;
    $gst = 0;

if (isset($_POST['save_sale']) && $_POST['save_sale'] === '1') {                     //**** Process Submit - Transaction Save ******/
	 $voucher_num = '';
	try {
		$dbh->beginTransaction();
		
		$party_state = $_POST["party_state"] ;
        if (strlen(trim($party_state)) == 0){
			$gst_txn_type = "local" ;
			$x= 1 ;
		}
		else{
			if ($comp_state == $party_state) {
				   $gst_txn_type = "local" ;
				   $x= 2 ;
			}					
		else {
				$gst_txn_type = "interstate" ;
				$x= 3 ;
			}
		}
		
		if (isset($_POST["manual"])){		
			$voucher_num = $_POST["voucher_num"] ;
		}
		else {
			$voucher_num = nextDocNumber($dbh, $biz_id, $txn_type); 
		}
		
		if ($voucher_num === '') {
			throw new RuntimeException('Sales voucher number is required.');
		}

		$voucher_date = $_POST["voucher_date"] ;
		$ord_ref_num = $_POST["ord_ref_num"] ; 
		$ord_ref_date = trim((string)($_POST['ord_ref_date'] ?? ''));
		$party_id = $_POST["party_id"] ;
		$party_name = $_POST["party_name"] ;
		$party_address = $_POST["party_address"] ;
		$party_pincode = $_POST["party_pincode"] ;
		$party_phone = $_POST["party_phone"] ;		
		$party_gstin = $_POST["party_gstin"] ;
		$remark_txn = $_POST["remark_txn"] ;

		if ($party_id <= 0 || $party_name === '') {
			throw new RuntimeException('Select a party first.');
		}

		// ** Check Duplicate voucher number prior to insert ***
		$chk = $dbh->prepare("
			SELECT COUNT(*) 
			FROM table_invoice_header 
			WHERE biz_id = :biz_id 
			  AND txn_type = :txn_type 
			  AND invoice_num = :invoice_num
		");

		$chk->execute([
			':biz_id' => $biz_id,
			':txn_type' => $txn_type,
			':invoice_num' => $voucher_num
		]);

		if ((int)$chk->fetchColumn() > 0) {
			throw new RuntimeException("Voucher number already exists.");
		}

		// ** Check Duplicate voucher number prior to insert ***

		$head_stmt = $dbh->prepare("
			INSERT INTO table_invoice_header
			(
				txn_type, biz_id, invoice_num, invoice_dt, ref_doc_no, ref_doc_date,
				note, invoice_cust_id, cust_name, bill_to_address, bill_to_state,
				bill_to_pincode, bill_to_phone, bill_to_gstin, gst_txn_type,
				invoice_created_by, created_dtm
			)
			VALUES
			(
				:txn_type, :biz_id, :invoice_num, :invoice_dt, :ref_doc_no, :ref_doc_date,
				:note, :invoice_cust_id, :cust_name, :bill_to_address, :bill_to_state,
				:bill_to_pincode, :bill_to_phone, :bill_to_gstin, :gst_txn_type,
				:invoice_created_by, :created_dtm
			)
		");

		$head_stmt->execute([
			':txn_type'           => $txn_type,
			':biz_id'             => $biz_id,
			':invoice_num'        => $voucher_num,
			':invoice_dt'         => $voucher_date,
			':ref_doc_no'         => $ord_ref_num,
			':ref_doc_date'       => $ord_ref_date,
			':note'               => $remark_txn,
			':invoice_cust_id'    => $party_id,
			':cust_name'          => $party_name,
			':bill_to_address'    => $party_address,
			':bill_to_state'      => $party_state,
			':bill_to_pincode'    => $party_pincode,
			':bill_to_phone'      => $party_phone,
			':bill_to_gstin'      => $party_gstin,
			':gst_txn_type'       => $gst_txn_type,
			':invoice_created_by' => $login_user,
			':created_dtm'        => $dtm
		]);

		$invoice_id = $dbh->lastInsertId();


			$ship_stmt = $dbh->prepare("
				UPDATE table_invoice_header
				SET diff_shp_add = :diff_shp_add,
					shp_party_name = :shp_party_name,
					shp_address = :shp_address,
					shp_state = :shp_state,
					shp_pincode = :shp_pincode,
					shp_phone = :shp_phone,
					shp_gstin = :shp_gstin
				WHERE invoice_id = :invoice_id
			");

			if (isset($_POST['diff_ship'])) {
				$ship_stmt->execute([
					':diff_shp_add'   => 'Y',
					':shp_party_name' => $_POST["party2_name"],
					':shp_address'    => $_POST["party2_address"],
					':shp_state'      => $_POST["party2_state"],
					':shp_pincode'    => $_POST["party2_pincode"],
					':shp_phone'      => $_POST["party2_phone"],
					':shp_gstin'      => $_POST["party2_gstin"],
					':invoice_id'     => $invoice_id
				]);
			} else {
				$ship_stmt->execute([
					':diff_shp_add'   => 'N',
					':shp_party_name' => '',
					':shp_address'    => '',
					':shp_state'      => '',
					':shp_pincode'    => '',
					':shp_phone'      => '',
					':shp_gstin'      => '',
					':invoice_id'     => $invoice_id
				]);
			}


		/* Process Lines Items  */

		$outp = 0;
		$total_cgst = 0 ;
		$total_sgst = 0 ;
		$total_igst = 0 ;
		$total_gst_amt = 0 ;
		$round_off_amt = 0;

		$items = $_POST["item_id"] ?? [];
		if (empty($items)) {
			throw new RuntimeException("Add at least one item.");
		}


		$det_stmt = $dbh->prepare("
				INSERT INTO table_invoice_details
				(
					biz_id, parent_invoice_id, item_srl_no, item_id, item_type,
					item_name, item_note, uom, qty, price,
					discount_mode, discount_amt, discount_pct, total_amt,
					hsn_code, gst_pct, CGST, SGST, IGST, gst_amt
				)
				VALUES
				(
					:biz_id, :invoice_id, :item_srl_no, :item_id, :item_type,
					:item_name, :item_note, :uom, :qty, :price,
					:discount_mode, :discount_amt, :discount_pct, :total_amt,
					:hsn_code, :gst_pct, :cgst, :sgst, :igst, :gst_amt
				)
			");
		
		for ($i = 0; $i < count($_POST["item_id"]); $i++) {
				$rec_status = $_POST['rec_status'][$i];	  // Values - new, upd, del 		
				$item_type = $_POST['item_type'][$i];				
				$item_id = $_POST['item_id'][$i];
				$item_name = $_POST['item_name'][$i];
				$remark_item = $_POST['remark_item'][$i] ;
				
				$hsn_sac = $_POST['hsn_sac'][$i];
				$uom =	$_POST['uom'][$i];
				$std_price = (float) $_POST['item_price'][$i] ;
				$sale_qty = (float) $_POST['quantity'][$i] ;
				$item_gst = (float) $_POST['itemGST'][$i] ;
				
				$discount_mode=$_POST['discMode'][$i] ;
				$discAmt = (float) $_POST['discAmt'][$i] ;
				

				if ($item_type === 'ROUND_OFF') {
					// allow negative price, force qty=1, gst=0, no discounts
					$sale_qty = 1;
					$item_gst = 0;
					$discount_mode = 'NONE';
					$discount_amt = 0;
					$discount_pct = 0;
					// do NOT clamp std_price for ROUND_OFF
				} else {
					if ($sale_qty < 0) $sale_qty = 0;
					if ($std_price < 0) $std_price = 0;
				}

				if ($sale_qty <= 0) { continue; }   // if sale_qty is 0.. go head do nothing -

				
				if ($discount_mode == 'AMT') {
					if ($discAmt < 0) $discAmt = 0;
					if ($discAmt > $std_price) $discAmt = $std_price;
					
					$discount_amt = $discAmt ;
					$discount_pct = 0 ;
					$finalPrice = $std_price - $discAmt ; 
				}
				else if ($discount_mode == 'PCT'){
					if ($discAmt < 0) $discAmt = 0;
					if ($discAmt > 100) $discAmt = 100;
					
					$discount_amt = 0 ;
					$discount_pct = $discAmt ;
					$finalPrice = $std_price - ( $std_price * $discAmt)/100 ;
				}
				else {
					$discount_amt = 0 ;
					$discount_pct = 0 ;
					$finalPrice = $std_price ;
				}
				
				$subTotal = $finalPrice * $sale_qty ;

					if ($gst_txn_type == 'local'){
						$cgst = $subTotal * ($item_gst/200) ;
						$sgst = $subTotal * ($item_gst/200) ;
						$igst = 0 ;
					}
					else
					{
						$igst = $subTotal * ($item_gst/100) ;
						$sgst = 0 ;
						$cgst = 0 ;
					}
				$gst_amt = $cgst + $sgst + $igst ;
				$lineTotal=$subTotal + $gst_amt ;
				$item_srl_no = $i +1 ;

					$det_stmt->execute([
						':biz_id'        => $biz_id,
						':invoice_id'    => $invoice_id,
						':item_srl_no'   => $item_srl_no,
						':item_id'       => $item_id,
						':item_type'     => $item_type,
						':item_name'     => $item_name,
						':item_note'     => $remark_item,
						':uom'           => $uom,
						':qty'           => $sale_qty,
						':price'         => $std_price,
						':discount_mode' => $discount_mode,
						':discount_amt'  => $discount_amt,
						':discount_pct'  => $discount_pct,
						':total_amt'     => $subTotal,
						':hsn_code'      => $hsn_sac,
						':gst_pct'       => $item_gst,
						':cgst'          => $cgst,
						':sgst'          => $sgst,
						':igst'          => $igst,
						':gst_amt'       => $gst_amt
					]);
								
				$invoice_detail_id = $dbh->lastInsertId() ; ;

				// Prevent Stock Deduction for Charges
				/*** Adjust Quantity **/			

				if ($item_type !== 'CHARGE' && $item_type !== 'ROUND_OFF') {
					$qty=$item_obj->reduceItemQty($dbh, $biz_id, $item_id, $sale_qty) ;
					$id=$stk_j->insert_stock_journal($biz_id,$item_id, $sale_qty,0, $qty, "Sale Item:$voucher_num",$invoice_id,$invoice_detail_id,"$login_user", "$dtm") ;				
				}	
				
				if ($item_type === 'ROUND_OFF') {
					$round_off_amt += $subTotal;
				} 
				else {
				$outp = $outp + $subTotal;
				$total_cgst = $total_cgst + $cgst ;
				$total_sgst = $total_sgst + $sgst ;
				$total_igst = $total_igst + $igst ;
				$total_gst_amt = $total_gst_amt + $gst_amt ;
				}
		}
		
		 // 4) Update header totals
		 
			$net_amt=round($outp + $total_gst_amt + $round_off_amt,2) ;  // Round upto two digit paise -	
			$update_invoice_header = "UPDATE table_invoice_header set total_amt=  $outp , CGST= $total_cgst, SGST=$total_sgst,
			 igst=$total_igst, total_tax = $total_gst_amt, net_amt = $net_amt  where invoice_id = $invoice_id " ;
			 
			if ($debug) echo $update_invoice_header ;
			$result = $dbh->exec($update_invoice_header) ;
					
			// ===== Ledger Journal Post: SALES (name-based) =====

			$docType   = 'SalesInv';
			$docId     = (int)$invoice_id;
			$docNum    = $voucher_num;
			$jrnlDate  = $voucher_date;
			$createdBy = $login_user;

			// Resolve system ledgers by fixed names
			$L_SALES  = ledger_id_by_name($dbh, $biz_id, 'Sales Revenue');
			$L_CGST   = ($total_cgst > 0) ? ledger_id_by_name($dbh, $biz_id, 'Output CGST') : null;
			$L_SGST   = ($total_sgst > 0) ? ledger_id_by_name($dbh, $biz_id, 'Output SGST') : null;
			$L_IGST   = ($total_igst > 0) ? ledger_id_by_name($dbh, $biz_id, 'Output IGST') : null;
			$L_ROUND  = ledger_id_by_name($dbh, $biz_id, 'Rounding Difference');

			// Customer ledger: prefer the selected party ledger, else control ledger
			$L_AR = (int)$party_id ?: ledger_id_by_name($dbh, $biz_id, 'Accounts Receivable (Control)');

			// Amounts computed earlier
			$untaxed  = round((float)$outp, 2);
			$taxCGST  = round((float)$total_cgst, 2);
			$taxSGST  = round((float)$total_sgst, 2);
			$taxIGST  = round((float)$total_igst, 2);
			$taxTotal = round((float)$total_gst_amt, 2);
			$grand    = round((float)$net_amt, 2);

			$lines = [];
			// Dr Customer (net)
			$lines[] = ['ledger_id'=>$L_AR, 'debit'=>$grand];
			// Cr Sales (subtotal)
			if ($untaxed != 0.0)   $lines[] = ['ledger_id'=>$L_SALES, 'credit'=>$untaxed];
			// Cr Output taxes
			if ($L_CGST && $taxCGST != 0.0) $lines[] = ['ledger_id'=>$L_CGST, 'credit'=>$taxCGST];
			if ($L_SGST && $taxSGST != 0.0) $lines[] = ['ledger_id'=>$L_SGST, 'credit'=>$taxSGST];
			if ($L_IGST && $taxIGST != 0.0) $lines[] = ['ledger_id'=>$L_IGST, 'credit'=>$taxIGST];
			// Rounding (if needed)
			if ($round_off_amt > 0) {
				$lines[] = ['ledger_id' => $L_ROUND, 'credit' => $round_off_amt];
			} elseif ($round_off_amt < 0) {
				$lines[] = ['ledger_id' => $L_ROUND, 'debit' => abs($round_off_amt)];
			}
			
			// Post
			$lj = new Ledger_Journal($dbh);
			$lj->postDoubleEntry(
				biz_id:       $biz_id,
				jrnl_date:    $jrnlDate,
				src_txn_type: $docType,
				src_txn_id:   $docId,
				src_txn_num:  $docNum,
				created_by:   $createdBy,
				lines:        $lines
			);
			$dbh->commit();
			$_SESSION['sale_bs_add_success'] = [
					'invoice_num' => $voucher_num,
					'invoice_id'  => $invoice_id
				];

			header('Location: ' . $self_url . '?saved=1');
			exit;
			/*
			$alertMsg = "Sales Invoice Created!\nNo: {$voucher_num}\nAmount: " . number_format((float)$net_amt, 2);
			$target   = "bill-manage.php";

			echo "<script>
			  alert(" . json_encode($alertMsg) . ");
			  window.location.href = " . json_encode($target) . ";
			</script>";
			exit;
			*/
						
		} 
		catch (Throwable $e) {
			if ($dbh->inTransaction()) { $dbh->rollBack(); }
			error_log("SALE-SAVE: ".$biz_id.":".$voucher_num.":".$e->getMessage());
			echo "<div class='alert alert-danger'> Error In Saving Sales Voucher".$e->getMessage()."</div>";
		}
		// ===== End =====

	}
?>
<!doctype html>
<html lang="en">

<head>
  <title>Sales Form</title>
  <!-- Required meta tags -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=yes">


  <!-- Bootstrap CSS v3.3.7 -->
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" type="text/css" rel="stylesheet">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>



<style>
	.fld8 { width: 8ch; max-width: 8ch; }
    .fld12 { width: 12ch; max-width: 12ch; }

	.totbox { font-weight: bold; }
	.totrow td { background:#f5f5f5; }
	
	td.disc-mode { min-width: 90px; }
	td.disc-val  { min-width: 90px; }
	td.disc-mode select { height: 30px; padding: 4px 6px; }

	.customer-panel .form-group {
	  margin-bottom: 6px;
	}

	.customer-panel .control-label {
	  padding-top: 4px;
	}

	.customer-panel .form-control {
	  height: 30px;
	  padding: 4px 6px;
	}
	
	.bill-ship-header {
	  display: flex;
	  align-items: center;
	  justify-content: space-between;
	  min-height: 30px;
	  margin-bottom: 2px;
	}

	.bill-ship-title {
	  font-weight: bold;
	  color: #337ab7;
	}	
	
</style>
  
  <script type="text/javascript" >

 	function searchName()
	{
		var biz_id = document.getElementById('biz_id').value ;
		var cust_name = document.getElementById('srch_cust_name').value ;
		var act_grp = "customer" ;
		$.ajax({	
			url: "party-search-name-ajax.php",
			type: "post",
			data: {p_act_grp:act_grp, p_biz_id:biz_id, p_cust_name:cust_name} ,
			success: function (response) {			   
			   $("#searchOutput").html(response);
			   $("#searchOutput").css("display","block");	
			},
			error: function(jqXHR, textStatus, errorThrown) {
			   console.log(textStatus, errorThrown);
			}	
		});
	}
 </script>

<script type="text/javascript" >
 	function searchPhone()
	{
		var biz_id = document.getElementById('biz_id').value ;
		var cust_phone = document.getElementById('srch_cust_number').value ;
		var act_grp = "customer" ;

		$.ajax({	
			url: "party-search-contact-ajax.php",
			type: "post",
			data: {p_act_grp:act_grp, p_biz_id:biz_id,p_cust_number:cust_phone} ,
			success: function (response) {			   
			   $("#searchOutput").html(response);
			   $("#searchOutput").css("display","block");	
			},
			error: function(jqXHR, textStatus, errorThrown) {
			   console.log(textStatus, errorThrown);
			}
	
	
		});
	}
 </script>

 <script type="text/javascript" >
 	function searchEmail()
	{
		var biz_id = document.getElementById('biz_id').value ;
		var cust_email = document.getElementById('srch_cust_email').value ;
		var act_grp = "customer" ;

		$.ajax({	
			url: "party-search-email-ajax.php",
			type: "post",
			data: {p_act_grp:act_grp, p_biz_id:biz_id,p_cust_email:cust_email} ,
			success: function (response) {			   
			   $("#searchOutput").html(response);
			   $("#searchOutput").css("display","block");	
			},
			error: function(jqXHR, textStatus, errorThrown) {
			   console.log(textStatus, errorThrown);
			}	
		});
	}
	
	function set_party(val)
	{
	var str_array = val.split(":") ;
	//	alert(str_array) ;
	// document.getElementById("customer_info").innerHTML=str_array[1] ;
		
	 $.ajax({
	 type: 'post',
	 url: 'party-info-fetch-ajax.php',
	 data: {
		cust_id:str_array[0]
		},
	 success: function (response) {
	   //alert(response);
	   console.log(response) ;
	   var obj = JSON.parse(response) ;
	   document.getElementById("party_id").value = obj.account_id ;
	   document.getElementById("party_name").value = obj.account_name ;
	   document.getElementById("party_name_dup").value = obj.account_name ;
	   document.getElementById("party_address").value = obj.address ;
	   document.getElementById("party_phone").value = obj.phone_num ;	   
	   document.getElementById("party_state").value = obj.state ;
	   document.getElementById("party_pincode").value = obj.pincode ;
	   document.getElementById("party_gstin").value = obj.gstin ;
	   
	   	recalcAllTotalsSale();
		updateRoundOffIfPresent();

	   
	   document.getElementById("ord_ref_num").focus(); 
	  }
	 });
	
	
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


<script>
function toggleDocReferenceFields() {
  var num = $.trim($('#ord_ref_num').val() || '');
  var $date = $('#ord_ref_date');

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
  toggleDocReferenceFields();
  $('#ord_ref_num').on('input', toggleDocReferenceFields);
});
</script>

  
  
</head>

<body style="background-color:#ccf2ff;">
<div class ="container-fluid">

    <!-- place navbar here -->
    <div>
		<?php include 'header.inc.php'; ?>
	</div>
	  
	<div class="row">  
		<div class="col-sm-1"><a href='<?php echo $src_loc;?>' style='border-radius:0'>❮ Back</a> </div>
		<div class="col-sm-8"><h4 class="text-primary" style="text-align:center;">Sales Voucher Add</h4></div>
	</div>
</div>  
  
<main>
  <div class="container">
	<div id="flashArea"></div>  
    <form  id="saleForm" method='POST' >
			<input type="hidden" name="save_sale" value="1">
		 	<input type="hidden" id="biz_id" name="biz_id" value="<?php echo $biz_id;?>">	
            <input type="hidden" id ="src_loc" name ="src_loc" value ="<?php echo $src_loc;?>"/>			  		
      
	  
	<div class="form-group row" style="margin-bottom:2px;">
		<label class="control-label col-md-2" for="voucher_num">Sale Voucher No<span style="color:red">*</span></label>  
		<div class="col-md-2">
			<input name="voucher_num" id="voucher_num" required class="input-md" type="text" value="<?php echo $doc_num;?>"
			onchange="set_voucher_numbering_mode()"	>
		</div>
		<div class="col-md-2" style="margin-top:2px; font-size:10px; display:flex; align-items:center;">
			  <label style="font-weight:bold; margin:0; display:flex; align-items:center;">
				<input type="checkbox" name="manual" id="manual" style="margin:0 4px 0 0;">
				Manual Numbering
			  </label>
		</div>


		<label class="control-label col-md-2" for="voucher_date">Transaction Date<span style="color:red">*</span></label>  
		<div class="col-md-3">
			<input name="voucher_date" id="voucher_date" required=required class="input-md" type="date" value="<?php echo date('Y-m-d'); ?>">
		</div>   
	</div>
	
	<div class="row" style="margin-bottom:2px;margin-top:2px;">
		<label class="control-label col-md-2" for="ord_ref_num">Order Refernce Number</label>  
		<div class="col-md-2">
			<input name="ord_ref_num" id="ord_ref_num" class="input-md" type="text" >
		</div>
		<div class="col-md-2"></div>
		<label class="control-label col-md-2" for="ord_ref_date">Order Date</label>  
		<div class="col-md-3">
				<input type="date" name="ord_ref_date" id="ord_ref_date" class="input-md" disabled >
		</div>
	</div>

   <!-- ================= PARTY DETAILS BLOCK ================= -->
   
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

		<span style="margin-left:10px;">
		  <b>ID:</b>
		  <input readonly id="party_id" name="party_id"
				 style="width:50px; height:22px;">
		</span>

		<span>
		  <b>Name:</b>
		  <input readonly id="party_name" name="party_name"
				 style="width:220px; height:22px;">
		</span>

		<span style="margin-left:auto;">
		  <label style="font-weight:normal; margin:0;">
			<input type="checkbox"
				   id="cb_party_det"
				   checked
				   onchange="toggleParty(this)">
			Show/Hide Party Details
		  </label>
		</span>

	  </div>

	<div class="panel-body customer-panel" id="PartyDetails" style="padding:10px;">

		<div class="row">

		<!-- ================= BILL TO ================= -->
		<div class="col-md-6">
		  <div style="border:1px solid #ddd; padding:12px; border-radius:4px; background:#fafafa;">
		  
			<div class="bill-ship-header">
			  <span class="bill-ship-title">Bill To</span>
			</div>

<!--			<span style="font-weight:bold; color:#337ab7; margin-bottom:4px;">Bill To </span> -->

			<div class="row form-group">
			  <label class="col-md-2 control-label">Name</label>
			  <div class="col-md-10">
				<input readonly name="party_name_dup" id="party_name_dup"
					   class="form-control">
			  </div>
			</div>

			<div class="row form-group">
			  <label class="col-md-2 control-label">Address</label>
			  <div class="col-md-10">
				<textarea readonly name="party_address" id="party_address"
						  class="form-control" rows="2"></textarea>
			  </div>
			</div>

			<div class="row form-group">
			  <label class="col-md-2 control-label">State</label>
			  <div class="col-md-5">
				<input readonly name="party_state" id="party_state"
					   class="form-control">
			  </div>

			  <label class="col-md-1 control-label">PIN</label>
			  <div class="col-md-4">
				<input readonly name="party_pincode" id="party_pincode"
					   class="form-control">
			  </div>
			</div>

			<div class="row form-group">
			  <label class="col-md-2 control-label">GSTIN</label>
			  <div class="col-md-5">
				<input readonly name="party_gstin" id="party_gstin"
					   class="form-control">
			  </div>
			  <label class="col-md-1 control-label">Phone</label>
			  <div class="col-md-4">
				<input readonly name="party_phone" id="party_phone"
					   class="form-control">
			  </div>
			  
			</div>

		  </div>
		</div>

		<!-- ================= SHIP TO ================= -->
		<div class="col-md-6">
		  <div style="border:1px solid #ddd; padding:12px; border-radius:4px; background:#fdfdfd;">
		  <div class="bill-ship-header">
				<span class="bill-ship-title">Ship To</span>

			<span>
				<label style="font-weight:normal; margin:0;">
				  <input type="checkbox" name="diff_ship" id="diff_ship"
						 onchange="diffShipping(this)">
				  Different address
				</label>

				<button type="button"
						class="btn btn-default btn-xs"
						id="btnCopyBillToShip"
						style="margin-left:6px; display:none;"
						onclick="copyBillToShip()">
				  Copy from Bill-To
				</button>
			  </span>
			</div>
		  
<!--		  
			<div class="clearfix" style="margin-bottom:4px;">
				<span style="font-weight:bold; color:#337ab7;">Ship To </span>

				<span class="pull-right">
					<label style="font-weight:normal;">
					  <input type="checkbox" name="diff_ship" id="diff_ship"
							 onchange="diffShipping(this)">
					  Different address
					</label>

					<button type="button" class="btn btn-default btn-xs"
							id="btnCopyBillToShip"
							style="margin-left:6px; display:none;"
							onclick="copyBillToShip()">
					  Copy from Bill-To
					</button>
				</span>
			</div>
-->
			<div id="ShipTo" style="display:none; margin-top:10px;">

			  <div class="row form-group">
				<label class="col-md-2 control-label">Name</label>
				<div class="col-md-10">
				  <input name="party2_name" id="party2_name"
						 class="form-control">
				</div>
			  </div>

			  <div class="row form-group">
				<label class="col-md-2 control-label">Address</label>
				<div class="col-md-10">
				  <textarea name="party2_address" id="party2_address"
							class="form-control" rows="2"></textarea>
				</div>
			  </div>

			  <div class="row form-group">
				<label class="col-md-2 control-label">State</label>
				<div class="col-md-5">
				  <input name="party2_state" id="party2_state"
						 class="form-control">
				</div>

				<label class="col-md-1 control-label">PIN</label>
				<div class="col-md-4">
				  <input name="party2_pincode" id="party2_pincode"
						 class="form-control">
				</div>
			  </div>

			  <div class="row form-group">
				<label class="col-md-2 control-label">GSTIN</label>
				<div class="col-md-5">
				  <input name="party2_gstin" id="party2_gstin"
						 class="form-control">
				</div>
				<label class="col-md-1 control-label">Phone</label>
				<div class="col-md-4">
				  <input name="party2_phone" id="party2_phone"
						 class="form-control">
				</div>

			  </div>

			</div>
      </div>
    </div>

  </div>

 </div> <!-- panel-body -->
</div> <!-- panel -->


<div class="row" style="margin-bottom:2px;margin-top:10px;<?php if ($allow_remark_txn=='N') echo 'display:none;';?>">
<label class="control-label col-md-2" for="remark_txn">Remark</label>  
  <div class="col-md-10">
	<input name="remark_txn" id="remark_txn" class="form-control" type="text">
  </div>
</div>



<!-- Line Items Card -->
<div class="panel panel-default" id="ItemDetailsPanel" style="margin-top:15px;">

  <!-- Panel Header -->
  <div class="panel-heading"
       style="display:flex; align-items:center; justify-content:space-between;">
    <strong>Line Items</strong>

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

  <!-- Panel Body -->
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
  <button name="submit" class="btn btn-primary" type="submit" value="submit">SUBMIT</button>
</div>
</form>
</div>
</main>
<?php if ($success_flash): ?>
<div class="modal fade" id="saleBSSuccessModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
  <div class="modal-dialog modal-sm" role="document">
    <div class="modal-content">
      <div class="modal-header" style="background:#5cb85c;color:#fff;">
        <h4 class="modal-title">Sales Voucher Added</h4>
      </div>

      <div class="modal-body">
        <p>Sales voucher has been added successfully.</p>
        <p style="font-size:16px;">
          Invoice Number:<br>
          <strong><?= h($success_flash["invoice_num"] ?? "") ?></strong>
        </p>
      </div>

      <div class="modal-footer" style="text-align:center;">
        <a class="btn btn-primary" href="<?= h($self_url) ?>">Add Another</a>
        <a class="btn btn-default" href="<?= h($sales_list_url) ?>">Go to List Sales Voucher</a>
      </div>
    </div>
  </div>
</div>

<script>
$(function(){
  $("#saleBSSuccessModal").modal("show");
});
</script>
<?php endif; ?>

<footer>
    <!-- place footer here -->
    
</footer>

<!--------------------------  Party  Modal  ------------------------->
<div class="modal fade" id="PartyModal" role="dialog" style="z-index:10000;">
    <div class="modal-dialog modal-md">
      <div class="modal-content">
        <div class="modal-header" style="background:#ed7c65;">
          <button type="button" class="close" data-dismiss="modal" style="color:#FFFFFF; float:right; opacity: 1; font:18px bold; ">X</button>
          <h4 class="modal-title" style="color:#FFFFFF;">Select Party</h4>
        </div>
        <div class="modal-body" style="height:380px;">
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
				<button type="button" onclick="searchName()"> <i class="fa fa-list" aria-hidden="true"></i> </button>
			</div>
		</div>	

  	<div class="row">
				<div class="col-md-2">
					<b>Contact:</b>
				</div>

			<div class="col-md-8">
				<input type="text"  id="srch_cust_number" name="srch_cust_number"  placeholder="Phone Number" value=""/>
				<button type="button" onclick="searchPhone()"> <i class="fa fa-list" aria-hidden="true"></i> </button>
			</div>
	</div>
	
	<div class="row">
				<div class="col-md-2">
					<b>Email:</b>
				</div>

	  		<div class="col-md-8">
	  			<input type="text"  id="srch_cust_email" name="srch_cust_email" placeholder="Email" value=""/>
				<button type="button" onClick="searchEmail()"> <i class="fa fa-list" aria-hidden="true"></i> </button>
	  		</div>
	</div>
		
	 <hr>
	 <div id="searchOutput" style="width:auto; height:auto; display:none; z-index:1; border:1px solid gray;"></div>
	</div>
	
<!-- Fields in Customer add tab are names as "cst_" -->	
    <div id="customer_add" class="tab-pane fade" style="margin-left: 70px;">
<!--	  <form method="post" id="customer_add_form">  -->
	  <div class="form-group row">
	  		<div class="col-md-5">
		<p><b>Name:</b><input id="cst_name" name="cst_name" placeholder="Name" class="form-control input-md" type="text"></p>
	</div>
	<div class="col-md-5">
		<p><b>Contact:</b> <input type="text"  id="cst_number" name="cst_number" class="form-control"  placeholder="Phone Number" /></p>
	</div>
	</div>
	<div class="form-group row">
		<div class="col-md-5">
	  			<p><b>Address:</b> <input type="text"  id="cst_address" name="cst_address" class="form-control"  placeholder="Address" /></p>
	  		</div>

			<div class="col-md-5">
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

	  		<div class="col-md-5">
	  			<p><b>Email:</b> <input type="text"  id="cst_email" name="cst_email" class="form-control"  placeholder="Email" /></p>
	  		</div>
	  	</div>
	  	<div class="form-group row">
		<div class="col-md-5">
	  			<p><b>GSTIN:</b> <input type="text"  id="cst_gstin" name="cst_gstin" class="form-control"  placeholder="GSTIN" /></p>
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
<!--------------- Party Modal - End ----------->

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
/* ===== Quote-style item selection for saleBS-add.php ===== */

var saleRowCounter = 0;
var saleItemCache = Object.create(null);
var allowRemarkItem = <?php echo json_encode((string)$allow_remark_item); ?>;

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

function showTotalSafe(t){
  // This is your showTotal(t), but made defensive and aligned with created inputs.
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
      hasAnyLine = true;

      var t = this.id.split('_')[1];

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
var updatingRoundOff = false;
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

function recalcAllTotalsSale(){
  var totTaxable=0, totGst=0, totNet=0, totC=0, totS=0, totI=0;

  $('#js1 tr[id^="prodRow_"]').each(function(){
    var t = this.id.split('_')[1];

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

  $('#tot_taxable').text(money2(totTaxable));
  $('#tot_gst').text(money2(totGst));
  $('#tot_net').text(money2(totNet));
  $('#tot_cgst').text(money2(totC));
  $('#tot_sgst').text(money2(totS));
  $('#tot_igst').text(money2(totI));
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
</script>

<script>
$(function(){
  $('#btnAddRoundOff').on('click', function(){
    addRoundOffRowSale();
  });
});
</script>




</body>
</html>

<?php
function json_out($arr) {
    header('Content-Type: application/json');
    echo json_encode($arr);
    exit;
}

function ledger_id_by_name(PDO $dbh, int $biz_id, string $ledger_name): int {
    $q = $dbh->prepare("SELECT account_id FROM account_ledger WHERE biz_id = :b AND account_name = :n LIMIT 1");
    $q->execute([':b'=>$biz_id, ':n'=>$ledger_name]);
    $id = $q->fetchColumn();
    if (!$id) { throw new RuntimeException("System ledger missing: {$ledger_name}"); }
    return (int)$id;
}

function nextDocNumber(PDO $dbh, int $biz_id, string $txn_type): string {
    $doc_prefix = 'INV-';
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


?>