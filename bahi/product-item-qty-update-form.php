<?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/session.php';
include 'include/param.php';
//checksession();
$debug = 1;

$biz_id = $_SESSION['biz_id'] ;

$dtm = getLocalDtm(); 
$id=$_POST['item_id'];
$item_name = $_POST['item_name'] ;
$avail_qty = $_POST['avail_qty'] ;

if(isset($_POST['submit']))
{
	header("Location: product-item-avail.php");
}
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<link rel="icon" type="image/png" href="images/icon.png" />
<title>Product Item Update</title>

<meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
  
<script type="text/javascript">
function validateForm()
{
	 var ele = document.getElementsByName('stk_action');
     for (i = 0; i < ele.length; i++) {
          if (ele[i].checked)
                  action_val = ele[i].value;
            }
	alert(action_val) ;		
	var adj_qty = document.getElementById('adj_qty').value;
	if (adj_qty <= 0) {
		alert("Quantity must be greater than zero") ;
		return false;
	}		
	var bizID = document.getElementById('biz_id').value;	
	var itemID = document.getElementById('item_id').value;
	var narrText = document.getElementById('narr_text').value;

	$.ajax({
				type: "POST",
				url: "product-item-qty-update-ajax.php",
				data: {biz_id:bizID, item_id:itemID, stk_action:action_val, item_qty:adj_qty, narr_text:narrText },  
				success: function(response){
					alert(response) ;
//					window.location.reload(); 
			}
	});	

}
</script>

<body style="background-color:#ccf2ff">
<div class ="container-fluid" >   	<!-- body -->
	<div>
    <?php 
	include 'header.inc.php';
	?>
	</div>

  <div style="margin-top:50px;">
  <h2 class="text-primary text-center">Product Item Quantity Update</h2><br>
</div>      

<form class="form-horizontal" style="margin-left:30%;" method="POST" onSubmit="return validateForm(this)">
 <input id="biz_id" name="biz_id" type="hidden" readonly class="form-control input-md"  value="<?php echo $biz_id;?>" >
 <input id="item_id" name="item_id" type="hidden" readonly class="form-control input-md"  value="<?php echo $id;?>" >
<div class="form-group row">
  <label class="control-label col-md-2" for="item_name">Item Name</label>  
  <div class="col-md-3">
	<input id="item_name" name="item_name"  readonly class="form-control input-md" type="text" value="<?php echo $item_name;?>" >
  </div>
</div>

<div class="form-group row">
  <label class="control-label col-md-2" for="avail_qty">Available Quantity</label>  
  <div class="col-md-3">
	<input  name="avail_qty"  readonly class="form-control input-md" type="text" value="<?php echo $avail_qty;?>" >
  </div>
			</div>


<div class="form-group row">
 <label class="control-label col-md-2" for="stk_action">Add/Reduce<span style="color:red">*</span></label>  
  <div class="col-md-3">
		<input type="radio" id="stk_add" name="stk_action" value="add" required>
		<label for="stk_add">Add</label> &nbsp; &nbsp;&nbsp;&nbsp;
		<input type="radio" id="stk_reduce" name="stk_action" value="reduce">
		<label for="stk_reduce">Reduce</label>
  </div>
</div>

<div class="form-group row">
<label class="control-label col-md-2" for="adj_qty">Adjustment Quantity<span style="color:red">*</span></label>  
  <div class="col-md-5"> 
	<input  id="adj_qty" name="adj_qty"  required  type="text" class="form-control input-md" style="width:40%;">
  </div> 
</div>

<div class="form-group row">
<label class="control-label col-md-2" for="narr_text">Narration<span style="color:red">*</span></label>  
  <div class="col-md-5">
	<input  id="narr_text" name="narr_text"  required class="form-control input-md" type="text" >
  </div> 
</div>

<div class="form-group" style="margin-left:19%;">
  <label class=" control-label" for="upload"></label>
  <div>
    <input type="submit" name="submit" class="btn btn-info" value="Submit" style="padding:10px 20px; border-radius:1px black; background-color:blue; "/>
<!--    <input type="reset"  name="Reset" class="btn" value="Reset" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/> -->
	<a href="product-item-avail"> <button type="button" style="padding:10px 20px; border-radius:0; background-color:blue;color:white;">Cancel</button></a>
	</div>
</div>
</form>
</div>
</body>
</html>
