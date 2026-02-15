<?php
include 'include/dbi.php';
include 'include/PDOConfig.php' ;
include 'include/param.php' ;


$debug = 0 ;
$dbh = new PDOConfig() ;
$biz = new MyBiz() ;
 

if(isset($_GET['view_id'])){
    $encoded_inv_id = $_GET['view_id'];
	$invoice_id = base64_decode($encoded_inv_id);
}


// Invoice Header Information 
	$inv_header_qry="SELECT biz_id, invoice_dt,invoice_num,invoice_cust_id, total_amt, gst_txn_type, cgst, sgst, igst, total_tax, net_amt FROM table_invoice_header where invoice_id='$invoice_id'";
	$inv_header_result=mysqli_query($conn,$inv_header_qry);
	$ih_row=mysqli_fetch_array($inv_header_result);
	$biz_id = $ih_row['biz_id'] ;
	$inv_cust_id=$ih_row['invoice_cust_id'];
	$net_amt=$ih_row['net_amt'];
	$inv_dt = $ih_row['invoice_dt'];
	$invoice_num = $ih_row['invoice_num'];
	$gst_txn_type = $ih_row['gst_txn_type'];

// Get Business Information
	$biz_qry = "SELECT * FROM biz_establishment WHERE biz_id = $biz_id" ;
	$comp_result= mysqli_query($conn,$biz_qry) ;
	$comp_row=mysqli_fetch_array($comp_result) ;
	$comp_name = $comp_row['biz_name'];
	$comp_add1 = $comp_row['biz_street'].' '.$comp_row['biz_area'].' '.$comp_row['biz_city'] ;
	$comp_state = $comp_row['biz_state'] ;
	$comp_pincode = $comp_row['biz_pin'] ;
	$comp_country = $comp_row['biz_country'] ;
	$comp_gstin = $comp_row['biz_gstin'] ;
	$comp_currency = $comp_row['biz_currency'] ;
	$comp_phone1 = $comp_row['biz_phone1'] ;
	$comp_email1 = $comp_row['biz_email'] ;

// If customer info, get customer info
	if ($inv_cust_id != 0){
		$buy_qry="SELECT * from account_ledger where account_id='$inv_cust_id'";
	//   echo $buy_qry;
		$buyer_qry=mysqli_query($conn, $buy_qry);
		$buy_row=mysqli_fetch_array($buyer_qry);
	//	$cust_gstin = SUBSTR($buy_row['gstin'],0,2);
	}


    $inv_det_qry="SELECT * FROM table_invoice_details where parent_invoice_id='$invoice_id' order by item_srl_no ";
	$inv_det_result = mysqli_query($conn, $inv_det_qry);

    $inv_discount_qry="SELECT count(*) FROM table_invoice_details where parent_invoice_id='$invoice_id' and ( discount_amt>0 or discount_pct> 0)";
	$inv_discount_result = mysqli_query($conn, $inv_discount_qry);
	$inv_discount_row = mysqli_fetch_array($inv_discount_result) ;
	$inv_discount_count = $inv_discount_row[0] ;
//	echo " Invoice discount availble:". $inv_discount_count ;
	

?>
<html>
<head>
	<link rel="icon" type="image/png" href="images/icon.png" />
	<title>POS Print Bill</title>

<meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

<style>
#seller, #item_det {
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


</style>

<script>
function printBill(){
  
	window.print();
 // document.getElementById("buttons_div").css("display","none");
}

function payment(){
  alert("Payment Successfully");
}
</script>
</head>

<body style="background-color:#f7ece6">
<div class="container">

	<div class="row">
	<div align="center">Sale Return Invoice</div>
	</div>

	<div class="row">
	
		<div class="col-md-6">
			<div style="float:left;">Invoice No. :<b><?php echo $invoice_num; ?></b></div>
		</div>
	
		<div class="col-md-6">
			<div style="float:right;"><b>Date: </b><?php echo $inv_dt ; ?>&nbsp;</div>
		</div>
	</div>

	<div class="row" id="seller">
		<div class="col-md-6">

			<b>Seller Details:</b><br>
			Name: <?php echo $comp_name; ?> <br>
			Address: <?php echo $comp_add1; ?> <br>
			State: <?php echo $comp_state."-".$comp_pincode; ?> <br>
			GSTIN : <?php echo $comp_gstin; ?>
		</div>
		<div class="col-md-6">

			<b>Buyer Details:</b><br>
			<?php if ($inv_cust_id != 0){ ?>
			Name: <?php echo $buy_row['account_name']; ?><br>
			Address: <?php echo $buy_row['address']; ?><br>
			State: <?php echo $buy_row['state']; ?> - <?php echo $buy_row['pincode']; ?><br>			
			Contact: <?php echo $buy_row['phone_num']; ?><br>
			Email: <?php echo $buy_row['email']; ?><br>
			<?php }
			else
			{
				echo "Name: Cash " ;
			}
			?>
		</div>
		
	   
		
	  </div>
</div>
	  
<br>
<div class="container" id="item_det">
	<div class="row">
	  	<div class="col-md-1" style="display:inline;">Sr No</div>
		<div class="col-md-2" style="display:inline;">Item Name</div>
		<div class="col-md-1" style="display:inline;text-align:center;">Quantity</div>
		<div class="col-md-1" style="display:inline;text-align:center;">UOM</div>
		<div class="col-md-1" style="display:inline;text-align:center;">Unit Price</div>
		
		<?php 
		if ($inv_discount_count >0) {
			echo '<div class="col-md-1" style="display:inline;text-align:center;">Discount</div>';

		}
		?>

		<div class="col-md-1" style="display:inline;text-align:center;">Sub Total</div>
		<div class="col-md-1" style="display:inline;text-align:center;">Tax Pct</div>
		<div class="col-md-1" style="display:inline;text-align:center;">Tax Amt</div>
	</div>
	<?php 
	$i = 1;
	$total_amount=0;
	$total_gst_perc =0;
	  while($row = mysqli_fetch_array($inv_det_result))
	  {
		$item_id= $row['item_id'];
		?>
	<div class="row">
		<div class="col-md-1" style="display:inline;"><?php echo $row['item_srl_no']; ?></div>
		<div class="col-md-2" style="display:inline;"><?php echo $row['item_name']; ?></div>
		<div class="col-md-1" style="display:inline;text-align:center;"><?php echo $row['qty']; ?></div>
		<div class="col-md-1" style="display:inline;text-align:center;"><?php echo $row['uom']; ?></div>
		<div class="col-md-1" style="display:inline;text-align:center;"><?php echo $row['price']; ?></div>
		<?php
		if ($inv_discount_count >0 ){
		   echo '<div class="col-md-1" style="display:inline;text-align:center;"><?php echo $row["discount_amt"]; ?></div>';
		}  
		?>
		 <div class="col-md-1" style="display:inline;text-align:center;"><?php echo $row['total_amt']; ?></div>
		<div class="col-md-1" style="display:inline;text-align:center;"><?php echo $row['gst_pct']; ?></div>
		<div class="col-md-1" style="display:inline;text-align:center;"><?php echo $row['gst_amt']; ?></div>
	</div>
	<?php
	}
	?>
</div>

<div class="container">

    <hr id="line">

	<div class="row" >
		<div class="col-md-6"> </div>
		<div class="col-md-2">
			<b>Total</b>
		</div>
		<div class="col-md-1" >	
			<span style="float:right;"><?php echo $ih_row['total_amt']; ?></span>
		</div>
		<div class="col-md-3">	</div>
		
	</div>
			
		 <?php if ($gst_txn_type == "local") { ?>

	<div class="row" >
		<div class="col-md-6"> </div>
		<div class="col-md-2">
		<b>CGST</b>
		</div>
		<div class="col-md-1" >
			<span style="float:right;"><?php echo $ih_row['cgst']; ?></span>
		</div>
		<div class="col-md-3">	</div>
	</div>			 
		<div class="row" >
		<div class="col-md-6"> </div>
		<div class="col-md-2">
		<b>SGST</b>
		</div>
		<div class="col-md-1" >
			<span style="float:right;"><?php echo $ih_row['sgst']; ?></span>
		</div>
		<div class="col-md-3">	</div>
	</div>			 

	 <?php } else { ?>
 	<div class="row" >
		<div class="col-md-6"> </div>
		<div class="col-md-2">
		<b>IGST</b>
		</div>
		<div class="col-md-1" >
			<span style="float:right;"><?php echo $ih_row['igst']; ?></span>
		</div>
		<div class="col-md-3">	</div>
	</div>			 

	 <?php } ?>		
	<div class="row" >
		<div class="col-md-6"> </div>
		<div class="col-md-2">
			<b>Net</b>
		</div>
		<div class="col-md-1" >
			<span style="float:right;"><?php echo $ih_row['net_amt']; ?></span>
		</div>
		<div class="col-md-3">	</div>

	</div>		
 
 
	<div id="buttons_div">
		<div style="float:right; margin-top:3px;">
			<button class="btn-primary btn-lg" onclick="printBill()">Print Bill</button>
		 &nbsp; &nbsp;
		</div>
	</div>
</div>

</body>
</html>