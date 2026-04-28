<?php
include 'include/dbo.php';
include 'include/param.php';
include 'include/amount-in-words.php';

$debug = 0;
$dbh = new dbo();

function fmt_amt($amount) {
    return number_format((float)$amount, 2, '.', '');
}


if (isset($_GET['view_id'])) {
    $encoded_inv_id = $_GET['view_id'];
    $invoice_id = base64_decode($encoded_inv_id);
}

// Fetch invoice header
$inv_header_stmt = $dbh->prepare("SELECT * FROM table_invoice_header WHERE invoice_id = :invoice_id");
$inv_header_stmt->execute(['invoice_id' => $invoice_id]);
$ih_row = $inv_header_stmt->fetch(PDO::FETCH_ASSOC);

$biz_id = $ih_row['biz_id'];
$inv_cust_id = $ih_row['invoice_cust_id'];
$net_amt = $ih_row['net_amt'];
$inv_dt = $ih_row['invoice_dt'];
$invoice_num = $ih_row['invoice_num'];
$gst_txn_type = $ih_row['gst_txn_type'];

include "company-info.php";
include "config-sales-invoice-info.php";
$extended_columns = true ;

// Optional: Fetch customer info
$buy_row = [];
if ($inv_cust_id != 0) {
    $buyer_stmt = $dbh->prepare("SELECT * FROM account_ledger WHERE account_id = :account_id");
    $buyer_stmt->execute(['account_id' => $inv_cust_id]);
    $buy_row = $buyer_stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch invoice items
$inv_det_stmt = $dbh->prepare("SELECT * FROM table_invoice_details WHERE parent_invoice_id = :invoice_id AND item_type != 'CHARGE' ORDER BY item_srl_no");
$inv_det_stmt->execute(['invoice_id' => $invoice_id]);
$invoice_items = $inv_det_stmt->fetchAll(PDO::FETCH_ASSOC);

// Discount check
$discount_stmt = $dbh->prepare("SELECT COUNT(*) FROM table_invoice_details WHERE parent_invoice_id = :invoice_id AND (discount_amt > 0 OR discount_pct > 0)");
$discount_stmt->execute(['invoice_id' => $invoice_id]);
$inv_discount_count = $discount_stmt->fetchColumn();

// Payments (if enabled)
$rct_details = [];
$rct_amt = 0;
if ($show_pymt == "Y") {
    $rct_stmt = $dbh->prepare("SELECT COUNT(*) AS count, SUM(txn_amount) AS total FROM money_txn WHERE reference_id = :invoice_id");
    $rct_stmt->execute(['invoice_id' => $invoice_id]);
    $rct_row = $rct_stmt->fetch(PDO::FETCH_ASSOC);
    $rct_count = $rct_row['count'];
    $rct_amt = (float)$rct_row['total'];

    if ($rct_count > 0) {
        $rct2_stmt = $dbh->prepare("SELECT * FROM money_txn WHERE reference_id = :invoice_id");
        $rct2_stmt->execute(['invoice_id' => $invoice_id]);
        $rct_details = $rct2_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<html>
<head>
<link rel="icon" type="image/png" href="images/icon.png" />
<title>Print Bill</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

<style>
#invoice , #seller, #item_det, #invoice_footer, #invoice_tnc {
border: 1px solid black;
border-collapse: collapse;
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
table, th, tr, td {
border: 1px solid black;
border-collapse: collapse;	
}

@page {
    size: A4;
    margin: 0;
}

body {
    margin: 0;
	padding: 0 ;
}

th, td {
    padding: 2px;
    vertical-align: top;
}

#tax_details td, #tax_details tr {
    padding: 4px 6px !important;
    line-height: 1.2 !important;
}

</style>

<script>
function printBill(inv_num){
	document.title="Invoice:"+inv_num ;	
	window.print();
}

</script>
</head>

<body style="background-color:#f7ece6">
<div class="container" id="invoice">
<div id="invoice_header">
	<table style="width: 100%;">
	<tr>
	<td style="width:15%;">
	<?php
	  if ($logo_img_loc != NULL)
	     echo "<img src='../$logo_img_loc' width='200px'>" ;
	 ?>
	</td>
	<td style="text-align:center;">
    	<h2 style="color:red;font-weight: bold;"> <?php echo $comp_name; ?> </h2>
    	<?php echo $comp_add1; ?> &nbsp; <?php echo $comp_state."-".$comp_pincode; ?> <br>
    	Phone: <?php echo $comp_phone1; ?> <?php if ($comp_tax_reg_status=="R") echo " GSTIN:".$comp_gstin; ?>
		<?php if ($enable_pharma=="Y") echo "<br> Drug License Number:".$drug_lic_no; ?>
	</td>
	</tr>
	</table>

	<div align="center" style="margin-top:10px;"><b><?php if($comp_tax_reg_status=="R"){ echo $print_caption_gst; } else{ echo $print_caption ; } ?>
	</b> 
	</div>

	<div class="row">
		<div class="col-md-6">
			<div style="float:left;"><b>Invoice No. :&nbsp;<?php echo $invoice_num; ?></b></div>
		</div>
	
		<div class="col-md-6">
			<div style="float:right;"><b>Date:&nbsp; 
			<?php 
			$date=date_create($inv_dt);
			echo date_format($date,"d-m-Y"); ?>&nbsp; </b></div>
		</div>
	</div>

	<div id="seller">
    	<table style="width: 100%;">
		<tr>
		<td style="width:50%;padding-left:20px;">

			<?php 
			if ($ih_row['txn_type'] == 'SALES'){
						echo "<b>Bill To:</b><br>" ;
			}
			else
			{
										echo "<b>Bill From(Supplier Details):</b><br>" ;
										echo "Supplier Invoice Number and Date:".$ih_row['ref_doc_no'].":".$ih_row['ref_doc_date']."<br>" ;
			}
			
			if ($inv_cust_id != 0){ ?>
				Name: <?php echo $ih_row['cust_name']; ?><br>
				Address: <?php echo $ih_row['bill_to_address']; ?><br> 
				State: <?php echo $ih_row['bill_to_state']; ?>-<?php echo $ih_row['bill_to_pincode']; ?><br>
				Contact: <?php echo $ih_row['bill_to_phone']; ?><br>
				GSTIN: <?php echo $ih_row['bill_to_gstin']; ?><br>

			<?php }
			else
			{
				echo "Name: Cash " ;
			}

			if ($show_po_details == 'Y'){
				echo      "<b>PO Number: </b>". $ih_row['ref_doc_no'];
				echo "<br/><b>PO Date: </b>". $ih_row['ref_doc_date'];
			}		
	
			?>
		</td>
		<td style="width:50%;padding-left:20px;">
		<?php
		if ($ih_row['diff_shp_add']=='Y'){
			echo "<b>Ship To:</b><br>" ;
			?>
			Name: <?php echo $ih_row['shp_party_name']; ?><br>
			Address: <?php echo $ih_row['shp_address']; ?><br> 
			State: <?php echo $ih_row['shp_state']; ?>-<?php echo $ih_row['shp_pincode']; ?><br>
			Contact: <?php echo $ih_row['shp_phone']; ?><br>
			GSTIN: <?php echo $ih_row['shp_gstin']; ?><br>
			<?php
		}
		if ($show_despatch_det == 'Y'){
			echo "<b> Despatch Through:</b>". $ih_row['note'];
		}
		?>
		</td>
		</tr>
		</table>

		
	   
		
	</div>
</div>	  
<br>





<div id="item_det">
<table class="table" style="width:100%;table-layout: fixed; margin-bottom:0px;">
    <tr>
        <th style="width: 5%;">S No</th>
        <th style="width: 32%;">Item Name</th>
        <th style="text-align: center;width: 10%;">HSN /SAC</th>
        <th style="text-align: center;width: 8%;">UOM</th>
        <th style="text-align: center;width: 10%;">Unit Price</th>
        <?php if ($inv_discount_count > 0) echo '<th style="width: 10%; text-align: center;">Discount</th>'; ?>
        <th style="text-align: center;width: 8%;">Qty</th>
        <th style="text-align: center;width: 10%;">Total (Without Tax)</th>
		<?php if ($extended_columns) { ?>
			<th style="text-align: center;width: 7%;">Tax %</th>
			<th style="text-align: center;width: 10%;">Total</th>
		<?php } ?>
    </tr>
    <?php 
	$total_wo_charge = 0 ;
	foreach ($invoice_items as $row): 
	?>
    <tr>
        <td><?php echo $row['item_srl_no']; ?></td>
        <td style="word-wrap: break-word; max-width: 200px; white-space: normal; overflow-wrap: break-word;">
            <?php
                if ($row['item_type'] == 'CHARGE') echo "** ";
                echo $row['item_name'];
				if (!empty($row['item_note'])) {
					echo "<div style='margin-top:5px; font-size:smaller; white-space:pre-line;'>".htmlspecialchars($row['item_note'])."</div>";
				}
		?>
        </td>
        <td style="text-align: center;"><?php echo $row['hsn_code']; ?></td>
        <td style="text-align: center;">
            <?php echo ($row['item_type'] == 'CHARGE') ? " " : $row['uom']; ?>
        </td>
        <td style="text-align: right;padding-right:30px;">
            <?php echo ($row['item_type'] == 'CHARGE') ? " " : $row['price']; ?>
        </td>
        <?php if ($inv_discount_count > 0): ?>
            <td style='text-align: right;padding-right:30px;'>
                <?php
                if ($row['discount_mode'] == 'AMT') echo "AMT:" . $row['discount_amt'];
                if ($row['discount_mode'] == 'PCT') echo "PCT:" . $row['discount_pct'];
                ?>
            </td>
        <?php endif; ?>
        <td style="text-align: center;">
            <?php echo ($row['item_type'] == 'CHARGE') ? " " : $row['qty']; ?>
        </td>
        <td style="text-align: right;padding-right:30px;">
            <?php 
					echo $row['total_amt'];
					$total_wo_charge += $row['total_amt'] ;
			?>
        </td>
		<?php if ($extended_columns) { ?>
        <td style="text-align: center;"><?php echo $row['gst_pct']; ?></td>
        <td style="text-align: right;padding-right:30px;">
            <?php echo fmt_amt((float)$row['total_amt'] + (float)$row['gst_amt']); ?>
        </td>
		<?php } ?>
    </tr>
    <?php endforeach; ?>
</table>
</div>

<?php
$charges_rows = [];
$charges_total_amt = 0;
$charges_cgst = 0;
$charges_sgst = 0;
$charges_igst = 0;

$charge_stmt = $dbh->prepare("SELECT item_name, total_amt, gst_amt FROM table_invoice_details 
    WHERE parent_invoice_id = :invoice_id AND item_type = 'CHARGE'");

$charge_stmt->execute(['invoice_id' => $invoice_id]);

while ($row = $charge_stmt->fetch(PDO::FETCH_ASSOC)) {
    $charges_rows[] = $row;
    $charges_total_amt += (float)$row['total_amt'];

    if ($gst_txn_type === 'local') {
        $charges_cgst += (float)$row['gst_amt'] / 2;
        $charges_sgst += (float)$row['gst_amt'] / 2;
    } else {
        $charges_igst += (float)$row['gst_amt'];
    }
}
?>

<div id="tax_details">
<table class="table" style="border:none;" >
<tr style="border:none;">
    <td style="text-align:right;border:none;width:75%;"><b>Total (Without Tax)</b></td>
    <td style="float:right;border:none;"><?php echo number_format($total_wo_charge, 2); ?></td>
</tr>

<?php foreach ($charges_rows as $c): ?>
<tr style="border:none;">
    <td style="text-align:right;border:none;width:75%;"><b>Charge - <?php echo htmlspecialchars($c['item_name']); ?></b></td>
    <td style="float:right;border:none;"><?php echo number_format($c['total_amt'], 2); ?></td>
</tr>
<?php endforeach; ?>

<?php if ($gst_txn_type === 'local'): ?>
<tr style="border:none;">
    <td style="text-align:right;border:none;width:75%;"><b>CGST</b></td>
    <td style="text-align:right;border:none;"><?php echo number_format($ih_row['cgst'] , 2); ?></td>
</tr>
<tr style="border:none;">
    <td style="text-align:right;border:none;width:75%;"><b>SGST</b></td>
    <td style="text-align:right;border:none;"><?php echo number_format($ih_row['sgst'] , 2); ?></td>
</tr>
<?php else: ?>
<tr style="border:none;">
    <td style="text-align:right;border:none;width:75%;"><b>IGST</b></td>
    <td style="text-align:right;border:none;"><?php echo number_format($ih_row['igst'] , 2); ?></td>
</tr>
<?php endif; ?>

<?php
$net_amt_final = $ih_row['total_amt'] + $ih_row['cgst'] +   $ih_row['sgst'] +  $ih_row['igst'] ; 
// Donot print this. This may result in decimal value - 
?>
<tr style="border:none;">
    <td style="text-align:right;border:none;width:75%;"><b>Grand Total</b></td>
    <td style="text-align:right;border:none;"><?php echo number_format($ih_row['net_amt'], 2); ?></td>
</tr>
<tr style="border:none;">
    <td colspan="2" style="border:none;text-align:right;">
        <b>Amount in Words :</b> <?php echo convertNumberToWords(round($net_amt_final)); ?>
    </td>
</tr>
</table>
 </div>
 
 <?php if ($show_tnc=="Y") { ?>
 <div id="invoice_tnc" >
<table class="table" style="width: 100%;margin-bottom:0px;">
	<tr>
	<td style="width:100%">
	<?php
		echo str_replace("\r\n","<br>",$detail_tnc) ;
	?>
	&nbsp;
	</td>
	</tr>
</table>	
 </div>
 <?php
 }         // Show TnC
 ?>
 
 
 <div id="invoice_footer" >
<table class="table" style="width: 100%;margin-bottom:0px;">
	<tr>
	<td style="width:50%">
	<?php
	if ($show_bank == "Y"){
		echo str_replace("\r\n","<br>",$detail_bank) ;
	}
	?>
	&nbsp;
	</td>
	<td style="width:50%"><center>
	For : <?php echo $comp_name; ?>
	<br><br><br>
	Authorized Signatory
	</center>
	</td>
	</tr>
</table>	
 </div>

 <?php if ($receiver_sign=="Y") { ?>
 <div id="invoice_receiver" >
<table class="table" style="width: 100%;margin-bottom:0px;text-align:left;">
	<tr>
	<td style="width:100%">
	<?php
		echo "<br/>" ;
		echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Receivers Signature" ;
	?>
	&nbsp;
	</td>
	</tr>
</table>	
 </div>
 <?php
 }         // Show receiver sign
 ?>

 </div>   <!-- End of Invoice -->
 
<?php if ($show_pymt == "Y" && !empty($rct_details)): ?>
<div class="container">
    <div class="row">
        <h4>Payment Details</h4>
        <table style="width:100%;">
            <tr>
                <th>Date</th>
                <th>Receipt No</th>
                <th>Amount</th>
                <th>Mode</th>
                <th>Detail</th>
            </tr>
            <?php foreach ($rct_details as $rct2_row): ?>
            <tr>
                <td><?php echo $rct2_row['txn_dt']; ?></td>
                <td><?php echo $rct2_row['txn_id']; ?></td>
                <td><?php echo $rct2_row['txn_amount']; ?></td>
                <td><?php echo $rct2_row['money_ac']; ?></td>
                <td><?php echo $rct2_row['narr_txt']; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php 
endif; 
?>

<div id="buttons_div">
		<div style="text-align:center ; margin-top:3px;">
			<button class="btn-primary btn-lg" onclick="printBill('<?php echo $invoice_num; ?>')">Print Bill</button>
		&nbsp; &nbsp;
		</div>
	</div>
</div>

</body>
</html>