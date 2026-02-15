<?php
session_start();
include 'include/dbi.php';
include 'include/session.php';
include 'include/param.php';
checksession();
$biz_id = $_SESSION['biz_id'] ;

$inv_num=$_POST['sales_invoice_id'];
$selectsql="select * from table_invoice_header where invoice_num = '$inv_num' and biz_id=$biz_id";
$rs = mysqli_query($conn,$selectsql);
$rows=mysqli_fetch_array($rs);
$inv_id = $rows['invoice_id'] ;
$cust_id = $rows['invoice_cust_id'] ;
$cust_name = $rows['cust_name'] ;
?>
<html>
    <head>
	<title>Sales Return - Invoice View</title>
    <link rel="icon" type="image/png" href="images/icon.png" />
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta http-equiv="Content-Type" content="text/html;"/>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.0/jquery.min.js"></script>
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

<style>
/*  
#editable_table th {
  color:#ffffff;
 background: #39383d ;
 font-weight: bold;
}

input{
width: 80px;
}
*/

.inv_data{
	background-color : #d1d1d1;
}
.ret_entry{
	background-color : #99FFAA;
}
</style>
</head>


<body style="background-color:#f7ece6;">
	<div class ="container-fluid">
		<?php 
		include 'header.inc.php';
		?>
	</div>

	<div class ="container" style="margin-top:20px;">

	<h2 class="text-primary text-center"> Sales Return : View Invoice Details and Mark Return Items</h2>

<form class="form-horizontal" style="margin-bottom:20px;padding:5px;" action="return-sales-save.php" method="POST" name="itemForm" onSubmit="return confirmSave()">

<div class="form-group row">
  <label class="control-label col-md-2" for="id">Invoice Number</label>  
  <div>
    <input type="hidden" name ="doc_id" class="form-control-static col-md-2 inv_data" readonly value="<?php echo $rows['invoice_id']; ?>"/>
    <input type="text" name ="doc_id" class="form-control-static col-md-2 inv_data" readonly value="<?php echo $rows['invoice_num']; ?>"/>
  </div>
    <label class="control-label col-md-2" for="id">Date</label>  
  <div>
    <input type="text" name ="doc_dtm" class="form-control-static col-md-2 inv_data" readonly value="<?php echo $rows['invoice_dt']; ?>"/>
  </div>

</div>

<!-- Text input-->
<div class="form-group row">
  <label class="control-label col-md-2"  for="phone_no">Customer :</label>  
  <div>
   <input type="hidden" name="cust_id" class="form-control-static col-md-2 inv_data" readonly value="<?php echo $cust_id; ?>" />

   <input type="text" name="cust_name" class="form-control-static col-md-2 inv_data" readonly value="<?php echo $cust_name ; ?>"/>
   <input type="hidden" name="bill_to_state"  value="<?php echo $rows['bill_to_state'] ; ?>"/>
   <input type="hidden" name="bill_to_address"  value="<?php echo $rows['bill_to_address'] ; ?>"/>
   <input type="hidden" name="bill_to_pincode"  value="<?php echo $rows['bill_to_pincode'] ; ?>"/>
   <input type="hidden" name="bill_to_phone"  value="<?php echo $rows['bill_to_phone'] ; ?>"/>
   <input type="hidden" name="bill_to_gstin"  value="<?php echo $rows['bill_to_gstin'] ; ?>"/>

  </div>
   <label class="control-label col-md-2"  for="gst_txn_typ">GST Txn Type:</label>  
  <div>
    <input type="text" name="gst_txn_type" class="form-control-static col-md-2 inv_data" readonly value="<?php echo $rows['gst_txn_type'] ; ?>"/>
  </div>
 </div>
 
 <div class="form-group row">
	
	<label class="control-label col-md-2"  for="phone_no">Total Amount:</label>  
  <div>
   <input type="text" class="form-control-static col-md-2 inv_data" readonly value="<?php echo $rows['total_amt']; ?>"/>
  </div>
    <label class="control-label col-md-2" for="name">Total TAX:</label>  
  <div>
  <input type="text" class="form-control-static col-md-2 inv_data" readonly value="<?php echo $rows['total_tax']; ?>"/>
  </div>

</div>
<div class="form-group row">

   <label class="control-label col-md-2 "  for="emailID">Net Amount:</label>  
  <div>
  <input type="text" class="form-control-static col-md-2 inv_data" readonly value="<?php echo $rows['net_amt']; ?>"/>
	</div>

   <label class="control-label col-md-2" for="req_dtm">Payment Status:</label>  
  <div>
  	<input type="text" class="form-control-static col-md-2 inv_data" readonly value="<?php echo $rows['payment_status']; ?>"/>
  </div> 
 </div>
 
 <div class="form-group row">
 	<label class="control-label col-md-2"  for="phone_no">Return Date:</label>  
  <div>
    <input id="txn_date" name="txn_date" class="form-control-static col-md-2 ret_entry" value="<?php echo date('Y-m-d');?>" type="date" >
  </div>

    <label class="control-label col-md-2"  for="total_return_amt">Total Return Amount:</label>  
  <div>
   <input type="text" id="total_return_amt" name="total_return_amt" class="form-control-static col-md-2 ret_entry" readonly value="0"/>
  </div>
</div>
<div class="form-group row">
  <label class="control-label col-md-2" for="total_return_tax">Total Return TAX:</label>  
  <div>
  <input type="text"  id="total_return_tax" name="total_return_tax" class="form-control-static col-md-2 ret_entry" readonly value="0"/>
  </div>

   <label class="control-label col-md-2 "  for="net_return_amt">Net Return Amount:</label>  
  <div>
  <input type="text" id="net_return_amt" name="net_return_amt" class="form-control-static col-md-2 ret_entry" readonly value="0"/>
	</div>
 
 </div>  
  <h4 class="text-primary text-center"> Item Details</h4>

   <div class="row">
    <div class="col-md-1"></div>

  <div class="col-md-10">
  <table id="editable_table" class="table table-bordered table-striped">
     <thead>
      <tr>
        <th>#</th>
        <th>Item </th>
        <th>Buy<br>Quantity</th>
        <th>Price/GST %</th>
        <th>Total Amount</th>
        <th>Return Quantity</th>
        <th>Return Amount</ th>
		
      </tr>
    </thead>
       <tbody>
  
  <?php 
    $querysel="select * from table_invoice_details where parent_invoice_id = '$inv_id' order by item_srl_no";
  //  echo $querysel;
    $query=mysqli_query($conn,$querysel);
   
  $i=0 ;  
  while($fetch=mysqli_fetch_array($query))
  {
  ?>
    <tr>
        <td>   
		<input type="text"   name="invoice_details_id[]"  hidden value="<?php echo $fetch['invoice_details_id']; ?>"/>
		<input type="text" name="item_id[]"  hidden value="<?php echo $fetch['item_id']; ?>"/>
		<?php echo $fetch['item_srl_no']; ?></td>
        <td><?php echo $fetch['item_name'];?></td>
        <td> <input type="text" class="inv_data" id="buy_qty<?php echo $i;?>" name="buy_qty[]" size="4" readonly value="<?php echo $fetch['qty']; ?>"/></td>
        <td> <input type="text" class="inv_data" id="buy_price<?php echo $i;?>" name="buy_price[]" size="8"  readonly value="<?php echo $fetch['total_amt']/$fetch['qty']; ?>"/>
		<input type="text" class="inv_data" id="gst_tax<?php echo $i;?>" name="gst_tax[]" size="8"  readonly value="<?php echo $fetch['gst_pct']; ?>"/>
		
		</td>
        <td><?php echo $fetch['total_amt']; ?></td>
        <td> <input type="number" class="ret_entry" id="ret_qty_<?php echo $i;?>" name="ret_qty[]" style="width:80px;" value="0" onchange="validateQty(<?php echo $i;?>)"/></td>
        <td> <input type="text" class="ret_entry" id="ret_amt<?php echo $i;?>" name="ret_amt[]" size="8" readonly value="0"/></td>

    </tr>
  <?php
   $i++ ;
  }    
  ?>
    </tbody>
 
  
    </table>
	
   </div>
   <div class="col-md-1"></div>
   </div>
  <div class="row">
    <div class="col-md-5"></div>
    <div class="col-md-2">
    <input type="submit" name="Submit-Sales-Return" class="btn btn-info" value="Submit" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
    <input type="reset" name="cancel" class="btn btn-default" value="Cancel" onClick="location.href = 'pos-index.php';" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>

	
	</div>
    <div class="col-md-5"></div>
  </div>	
 </form> 
</div> 
 </body>  
 
 
</html>  
 <script LANGUAGE="JavaScript">

function confirmSave() {
var msg;
var tot_ret_amt = document.getElementById("total_return_amt") ;
if (tot_ret_amt.value == 0){
	alert("No item to return");
	return false ;
}
	
msg= "Are you sure to process the Sales Return? " ;
var agree=confirm(msg);
if (agree)
	return true ;
else
	return false ;
}
</script>

 
 
 <script type="text/javascript">
function validateQty(sno){
	var b_qty_arr = document.getElementsByName("buy_qty[]");
	var r_qty_arr = document.getElementsByName("ret_qty[]");
	var u_price_arr = document.getElementsByName("buy_price[]");
	var r_amt_arr = document.getElementsByName("ret_amt[]");
	var g_tax_arr = document.getElementsByName("gst_tax[]");

	var b_qty = Number( b_qty_arr[sno].value ) ;
	var r_qty = Number( r_qty_arr[sno].value ) ;
	var u_price = Number( u_price_arr[sno].value ) ;
	var r_amt = Number (r_amt_arr[sno].value) ;
	var g_tax = Number(g_tax_arr[sno].value) ;
	
	if ( b_qty < r_qty) {
		alert( "Returned Item Quantity: "+r_qty+" : is more than bought");
		r_qty_arr[sno].value = 0 ;
		r_amt_arr[sno].value = 0 ;
		return false ;
	}
	amt = r_qty * u_price ;
	r_amt_arr[sno].value = r_qty * u_price ;
	var ret_amt= 0 ;
	var ret_tax = 0 ;
	for (x=0; x<r_amt_arr.length; x++){
		ret_amt = Number(ret_amt) + Number(r_amt_arr[x].value)
		ret_tax = Number(ret_tax) + ( r_amt_arr[x].value * g_tax_arr[x].value)/100 ;
	}
	var tot_ret_amt = document.getElementById("total_return_amt") ;
	var tot_ret_tax = document.getElementById("total_return_tax") ;
	var net_ret_amt = document.getElementById("net_return_amt") ;
	tot_ret_amt.value = ret_amt ;
	tot_ret_tax.value = ret_tax ;
	net_ret_amt.value = ret_amt + ret_tax ;	
}
</script>
