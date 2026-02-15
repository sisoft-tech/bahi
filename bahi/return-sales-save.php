<?php
ob_start();
session_start();
/*
Name: return-sales-save.php
Called from : return-sales-entry.php
*/


include_once 'include/session.php';
include 'include/dbi.php';
include 'include/param.php';
include 'include/dbo.php' ;

include 'include/item.php';              // if not already included somewhere else
include 'include/ledger_journal.php';    // NEW: for double-entry posting
 
$debug = 0 ;

$dbh = new dbo() ;

$dtm=getLocalDtm(); 
$login_user = $_SESSION['pos_login'];
$item = new Item() ;

$biz_id = $_SESSION['biz_id'] ;	
include 'company-info.php' ;


if(isset($_POST['Submit-Sales-Return']))
{
	$ref_doc_id = $_POST['doc_id'];
	$ref_doc_dtm = $_POST['doc_dtm'] ;
	$cust_id = $_POST['cust_id'] ;
	$total_return_amt = $_POST['total_return_amt'] ;
	$total_return_tax = $_POST['total_return_tax'] ;
	$net_return_amt = $_POST['net_return_amt'] ;
	$gst_txn_type = $_POST['gst_txn_type'] ;
	
	$cust_name = $_POST['cust_name'] ;
	$bill_to_address = $_POST['bill_to_address'] ;
	$bill_to_state = $_POST['bill_to_state'] ;
	$bill_to_pincode = $_POST['bill_to_pincode'] ;
	$bill_to_phone = $_POST['bill_to_phone'] ;
	$bill_to_gstin = $_POST['bill_to_gstin'] ;
	
	$txn_date = $_POST['txn_date'] ;
	
	if ($gst_txn_type == "local"){
		$cgst = $total_return_tax/2 ;
		$sgst = $total_return_tax/2 ;
		$igst = 0 ;
	}
	else {
		$igst = $total_return_tax ;
		$cgst = 0 ;
		$sgst = 0 ;
	}

/* Voucher Number Generation - Start */

$txn_type = "SALES RETURN" ;
$doc_series_conf = "SELECT * FROM config_doc_prefix WHERE biz_id='$biz_id' and doc_type='$txn_type'" ; 
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
	$doc_prefix = "CN-" ;
	$len_sno = 3 ;
	$sno_start = 1 ;
	$sno_pad = 0 ;
}	
if ($debug) echo "<br>:".$doc_prefix.":".$len_sno.":".$sno_start.":".$sno_pad."<br>" ;

$prefix_length = strlen($doc_prefix)+1 ;  // One character after the prefix
$qry = "SELECT SUBSTR(invoice_num,$prefix_length)+1 as srl_no from table_invoice_header 
        where biz_id=$biz_id and invoice_num is not null and invoice_num like '$doc_prefix%' ORDER BY invoice_id DESC LIMIT 1" ;
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
$cn_num = $doc_prefix. substr(str_repeat($sno_pad, $len_sno) . $doc_sno, -$len_sno);  


	$insert_qry = "INSERT INTO `table_invoice_header`(`txn_type`, `biz_id`,`invoice_num`,  `invoice_dt`,  `ref_doc_no`, `ref_doc_date`, `invoice_cust_id`,`cust_name`, `bill_to_address`, `bill_to_state`,  `bill_to_pincode`, `bill_to_phone`, `bill_to_gstin`, `gst_txn_type`,`total_amt`, `cgst`,`sgst`,`igst`, `total_tax`,`net_amt`, `invoice_created_by`,`created_dtm`) 
	VALUES ('SALES RETURN', $biz_id,'$cn_num','$txn_date','$ref_doc_id','$ref_doc_dtm', '$cust_id','$cust_name','$bill_to_address','$bill_to_state','$bill_to_pincode','$bill_to_phone','$bill_to_gstin', '$gst_txn_type', $total_return_amt, '$cgst','$sgst','$igst',$total_return_tax, $net_return_amt,
	'$login_user','$dtm')" ;
	if ($debug) echo "<br>".$insert_qry ;
	$result= mysqli_query($conn,$insert_qry) ;
    $invoice_id = mysqli_insert_id($conn) ;
	if ($debug) echo '<br>Invoice ID:'. $invoice_id.":<br>" ; 

			$invoice_details_id = $_POST['invoice_details_id'] ;
			$item_id = $_POST['item_id'] ;
			$price = $_POST['buy_price'] ;
			$ret_qty = $_POST['ret_qty'] ;
			$gst_pct = $_POST['gst_tax'] ;
			
			$ord_sno = 0 ;

			for ($i=0; $i< count($invoice_details_id); $i++){
				echo $i.":".$item_id[$i].":".$price[$i].":".$ret_qty[$i].":".$gst_pct[$i].":<BR>" ;
				if ($ret_qty[$i] <= 0){
					echo "No Items Returned<br>" ;
				}
				else
				{
				$ord_sno++ ;
				$item_det=$item->getItemDetails($dbh,$biz_id, $item_id[$i]);
				echo $item_det[0].":".$item_det[1] ;
				$tot_amt = $price[$i]*$ret_qty[$i] ;
				$gst_amt = ($tot_amt * $gst_pct[$i])/100  ;
				if ($gst_txn_type == "local"){
					$cgst = $gst_amt/2 ;
					$sgst = $gst_amt/2 ;
					$igst = 0 ;
				}
				else {
					$igst = $gst_amt ;
					$cgst = 0 ;
					$sgst = 0 ;
				}

				$insert_inv_det = "INSERT INTO `table_invoice_details`( `parent_invoice_id`, `item_srl_no`, `item_id`, `item_name`, `uom`, `price`, `qty`,`total_amt`, `gst_pct`,`cgst`,`sgst`,`igst`, `gst_amt`) 
				VALUES ($invoice_id,$ord_sno, $item_id[$i] ,'$item_det[0]'  ,'$item_det[1]' , $price[$i] ,$ret_qty[$i], $tot_amt, $gst_pct[$i] ,$cgst,$sgst,$igst, $gst_amt) " ;
				
				if ($debug) echo "<br>".$insert_inv_det ;
				$result= mysqli_query($conn,$insert_inv_det) ;
				$invoice_detail_id = mysqli_insert_id($conn) ;
				}
		}
		
		    // ===== Ledger Journal Post: SALES RETURN (Credit Note) =====
    try {
        // Doc metadata
        $docType   = 'SalesReturn';         // logical type for reporting
        $docId     = (int)$invoice_id;
        $docNum    = $cn_num;
        $jrnlDate  = $txn_date;
        $createdBy = $login_user;

        // Resolve system ledgers by fixed names (same naming as Sales)
        $L_SALES  = ledger_id_by_name($dbh, $biz_id, 'Sales Revenue');
        $L_CGST   = ($cgst > 0) ? ledger_id_by_name($dbh, $biz_id, 'Output CGST') : null;
        $L_SGST   = ($sgst > 0) ? ledger_id_by_name($dbh, $biz_id, 'Output SGST') : null;
        $L_IGST   = ($igst > 0) ? ledger_id_by_name($dbh, $biz_id, 'Output IGST') : null;
        $L_ROUND  = ledger_id_by_name($dbh, $biz_id, 'Rounding Difference');

        // Customer ledger: prefer the customer ledger id; else AR control
        $L_AR = (int)$cust_id ?: ledger_id_by_name($dbh, $biz_id, 'Accounts Receivable (Control)');

        // Amounts (all positive figures)
        $untaxed  = round((float)$total_return_amt, 2);
        $taxCGST  = round((float)$cgst, 2);
        $taxSGST  = round((float)$sgst, 2);
        $taxIGST  = round((float)$igst, 2);
        $taxTotal = round((float)$total_return_tax, 2);
        $grand    = round((float)$net_return_amt, 2);

        // Ideal vs rounded; any difference goes to Rounding Difference
        $ideal    = round($untaxed + $taxTotal, 2);
        $roundAdj = round($grand - $ideal, 2);

        $lines = [];

        // For SALES RETURN we REVERSE the original sales entry:
        // Dr Sales Revenue (for subtotal)
        if ($untaxed != 0.0) {
            $lines[] = ['ledger_id' => $L_SALES, 'debit' => $untaxed];
        }

        // Dr Output taxes (reduce tax liability)
        if ($L_CGST && $taxCGST != 0.0) {
            $lines[] = ['ledger_id' => $L_CGST, 'debit' => $taxCGST];
        }
        if ($L_SGST && $taxSGST != 0.0) {
            $lines[] = ['ledger_id' => $L_SGST, 'debit' => $taxSGST];
        }
        if ($L_IGST && $taxIGST != 0.0) {
            $lines[] = ['ledger_id' => $L_IGST, 'debit' => $taxIGST];
        }

        // Rounding (if needed)
        if (abs($roundAdj) >= 0.01) {
            if ($roundAdj > 0) {
                // Net > ideal ? extra DEBIT to Rounding Difference
                $lines[] = ['ledger_id' => $L_ROUND, 'debit' => $roundAdj];
            } else {
                // Net < ideal ? extra CREDIT to Rounding Difference
                $lines[] = ['ledger_id' => $L_ROUND, 'credit' => abs($roundAdj)];
            }
        }

        // Cr Accounts Receivable / Customer for net credit note value
        if ($grand != 0.0) {
            $lines[] = ['ledger_id' => $L_AR, 'credit' => $grand];
        }

        // Post the journal using PDO-based Ledger_Journal
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
    } catch (Throwable $e) {
        // Don't block printing the credit note if ledger posting fails.
        error_log("SALES-RETURN-LEDGER: {$biz_id}:{$cn_num}: ".$e->getMessage());
    }
    // ===== End Ledger Journal for Sales Return =====

		
			
	}
			


    $qry="SELECT * FROM table_invoice_details where parent_invoice_id='$invoice_id' order by item_srl_no ";
	$result = mysqli_query($conn, $qry);
	$invoice_qry="SELECT * from table_invoice_header 
	where invoice_id='$invoice_id'";
//echo $invoice_qry;
	$invoice_result=mysqli_query($conn,$invoice_qry);
	$invoice_row=mysqli_fetch_array($invoice_result);
	$customer_id_ih=$invoice_row['invoice_cust_id'];
	$net_amt=$invoice_row['net_amt'];
	$invoice_dt = $invoice_row['invoice_dt'];
	$invoice_num = $invoice_row['invoice_num'];

	
	$rct_amt_qry = "Select sum(txn_amount) as PAID_AMT from money_txn where reference_id=$invoice_id";
	$rct_amt_result=mysqli_query($conn, $rct_amt_qry) ;
	$rct_amt_row=mysqli_fetch_array($rct_amt_result);
	$total_rct_amt = $rct_amt_row['PAID_AMT'] ;

	if ($customer_id_ih != 0){
		$buy_qry="SELECT * from account_ledger where account_id='$customer_id_ih'";
	 //   echo $buy_qry;
		$buyer_qry=mysqli_query($conn, $buy_qry);
		$buy_row=mysqli_fetch_array($buyer_qry);
		$_SESSION['customer_id'] = $buy_row['account_id'];
	}
?>
<html>
<head>
	<link rel="icon" type="image/png" href="images/icon.png" />
	<title>Sales Return Invoice</title>

<meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

<style>
#seller, #print {
border: 1px solid black;
border-collapse: collapse;
}
th,td{
	padding: 10px;
}
#line{
	 display: block;
    border: 1px dotted black;
}
#buttons_div{
margin-top:200px;
}
@media print{
   #buttons_div{
       display:none !important;
   }
}
#updated_amount{
  float: right;
  border:none; width:30%;
  font-size: 18px;
}
#updated_amount tr{
  line-height: 5px;
}
.heading_text{
  font-weight: bold;
  padding: 150px;
}


</style>

<script>
function printBill(){
  
	window.print();
 // document.getElementById("buttons_div").css("display","none");
}

</script>
</head>

<body style="background-color:#f7ece6">
<div class="container">

	<div class="row">
	  <div class="col-md-4">
	<div style="float:left;">Invoice No. :<b><?php echo $invoice_num; ?></b></div>
	</div>
	<div class="col-md-4">
	<div align="center">Sales Return Invoice</div>
	</div>
	<div class="col-md-4">
	<div style="float:right;"><b>Date: </b><?php echo $invoice_dt; ?>&nbsp;</div>
	</div>
	</div>

	<table id="seller" style="width:100%;">
	  <tr>
		<td>
			<b>Seller Details:</b><br>
			Name: <?php echo $comp_name; ?> <br>
			Address: <?php echo $comp_add1; ?> <br>
			State: <?php echo $comp_state." - ".$comp_pincode; ?> <br>
		</td>
		<td>
			<b>Buyer Details:</b><br>
			<?php if ($customer_id_ih != 0){ ?>
			Name: <?php echo $buy_row['account_name']; ?><br>
			Contact: <?php echo $buy_row['phone_num']; ?><br>
			Email: <?php echo $buy_row['email']; ?><br>
			<?php }
			else
			{
				echo "Name: Cash " ;
			}
			?>
		</td> 
	   
		
	  </tr>

	  
	</table>
<br>
	<table style="width:100%;" id="print">
	  <tr>
		<th>Sr No</th>
		<th>Item Name</th>
		<th>Quantity</th>
		<th>UOM</th>
		<th>Unit Price</th>
		<th>Sub Total</th>
		<th>Tax Pct</th>
		<th>Tax Amt</th>
	  </tr>
	<?php 
	$i = 1;
	$total_amount=0;
	$total_gst_perc =0;
	  while($row = mysqli_fetch_array($result))
	  {
		$item_id= $row['item_id'];
		?>
	<tr>  
		<td><?php echo $row['item_srl_no']; ?></td>
		<td><?php echo $row['item_name']; ?></td>
		<td><?php echo $row['qty']; ?></td>
		<td><?php echo $row['uom']; ?></td>
		<td><?php echo $row['price']; ?></td>
     	<td><?php echo $row['total_amt']; ?></td>
		<td><?php echo $row['gst_pct']; ?></td>
		<td><?php echo $row['gst_amt']; ?></td>
		  
	</tr>
	<?php
	}
	?>
	</table>

	 <hr id="line">

	 <table id="updated_amount" style="">

	   <tr>
		<th>Total</th>
		<td class="data_text"><?php echo $invoice_row['total_amt']; ?></td>
	 </tr>
	   <tr>
		<th>Tax</th>
		<td class="data_text"><?php echo $invoice_row['total_tax']; ?></td>
	 </tr>
	   <tr>
		<th>Net</th>
		<td class="data_text"><?php echo $invoice_row['net_amt']; ?></td>
	 </tr>
	 
	 
	 </table>
 
 
	<div id="buttons_div">
	<div style="float:left">
	  <a href="pos-index.php"><button class="btn-success btn-lg">Back To Home</button></a>
	</div>
	<div style="float:right; margin-top:3px;">
<!-- 
	<button id="total_discount_btn" data-toggle="modal" data-target="#total_discount_modal">Discount</button> 
	 <button class="btn-danger btn-lg" data-toggle="modal" data-target="#receipt_modal">Refund</button>
-->	 
	 <button class="btn-primary btn-lg" onclick="printBill()">Print Bill</button>
	 &nbsp; &nbsp;
	</div>
	</div>
</div>

</body>
</html>
<?php
function ledger_id_by_name(PDO $dbh, int $biz_id, string $ledger_name): int {
    $q = $dbh->prepare("
        SELECT account_id
        FROM account_ledger
        WHERE biz_id = :b AND account_name = :n
        LIMIT 1
    ");
    $q->execute([':b' => $biz_id, ':n' => $ledger_name]);
    $id = $q->fetchColumn();
    if (!$id) {
        throw new RuntimeException("System ledger missing: {$ledger_name}");
    }
    return (int)$id;
}
?>