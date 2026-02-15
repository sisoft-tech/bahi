<?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/session.php';
include 'include/param.php';
//checksession();
$debug = 0 ;

$biz_id = $_SESSION['biz_id'] ;
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<link rel="icon" type="image/png" href="images/icon.png" />
<title>Sales Return</title>

<meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
  

</script>

<script type="text/javascript" >
 	function check_sales_invoice(biz_id, sales_inv_num)
	{
		alert("Entered Data:"+biz_id+":"+sales_inv_num) ;
		var msgbox = $("#status");
		var txn_type = "SALES" ;

			$.ajax({
				type: "POST",
				url: "return-orig-inv-exists-ajax.php",
				data: {p_biz_id:biz_id, p_txn_type: txn_type, p_inv_num:sales_inv_num},  
				success: function(response){ 
				    console.log(response) ;
					msgbox.html(response);
  					if (response != "OK"){
							$("#sales_invoice_id").focus();
					}	
					}				
			}) ;
			
		
	};

 </script>


</head>
<body style="background-color:#f7ece6">
<div class ="container-fluid" >   	<!-- body -->
	<div>
    <?php 
	include 'header.inc.php';
	?>
	</div>

  <div style="margin-top:50px;">
  <h2 class="text-primary text-center">Sales Return</h2><br>

</div>

<form class="form-horizontal" style="margin-left:10%;" method="POST" action="return-sales-entry.php">
<div class="form-group row">
<label class="control-label col-md-2"  for="sales_invoice_id">Sales Invoice Number <span style="color:red">*</span></label>  
  <div class="col-md-2">
	<input  name="sales_invoice_id" id="sales_invoice_id"  placeholder="" required=required class="form-control input-md" type="text"
	onchange="check_sales_invoice(<?php echo $biz_id;?>, this.value)"/><span id="status"></span>
  </div>
  

</div>

<div class="form-group" style="margin-left:35%;">
  <label class=" control-label" for="upload"></label>
  <div>
    <input type="submit" name="submit" class="btn btn-info" value="Submit" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
    <input type="reset"  name="Reset" class="btn" value="Reset" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
	</div>
</div>
</form>
	</div>

	<div style="position:absolute; width:100%; left:0; right:0; margin-top:151px;">
		<?php //include("footer.inc.php"); ?>
	</div>

</body>
</html>
