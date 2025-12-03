<?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/session.php';
include 'include/param-pos.php';
//checksession();

date_default_timezone_set('Asia/Kolkata');
$dtm = date("Y/m/d H:i:s"); 

$debug= 1 ;

$biz_id = $_SESSION['biz_id'] ;
$uname = $_SESSION['login'] ;


if(isset($_POST['submit']))
{
	$ac_holder_name=$_POST['ac_holder_name'];
	$ac_number=$_POST['ac_number'];
	$ifsc_code=$_POST['ifsc_code'];
	$bank_name= $_POST['bank_name'];
	$branch_det=$_POST['branch_det'];
	$created_by=$uname;
	
	$insert_qry="INSERT INTO `biz_bank_details`(`biz_id`, `ac_numer`, `ac_holder_name`, `bank_ifsc_cd`, `bank_name`, `branch_add`, `created_dtm`, `created_by`) 
	VALUES ('$biz_id','$ac_number','$ac_holder_name','$ifsc_code','$bank_name','$branch_det','$dtm','$created_by') " ;
 
  if ($debug)  echo $insert_qry ;	
	$result= mysqli_query($conn,$insert_qry) ;
	
	if ($result==false){
		$error=mysqli_error($conn) ;
		echo "<BR>Error in Insert Add Product Group".$error ;
		die($error) ;
	}
//	header("Location: biz-profile.php");
}
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<link rel="icon" type="image/png" href="images/icon.png" />
<title>Product Group Add</title>

<meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>


 <!--Import Google Icon Font-->
      <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">     
       <!-- Compiled and minified CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0-rc.2/css/materialize.min.css">
    <!-- Compiled and minified JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0-rc.2/js/materialize.min.js"></script> 
	
<script type="text/javascript">
function validateForm()
{
	alert("Product Group Added Successfully....");
}
</script>

<body>
<div class ="container-fluid" >   	<!-- body -->
	<div>
	    <?php include("biz-header.php"); ?>
	</div>

  <div style="margin-top:20px;">
  <h2 class="text-center">Add Business Bank Accounts</h2><br>
</div>      

<form class="form-horizontal" style="margin-left:27%;" method="POST" onSubmit="return validateForm(this)">

<div class="form-group row">
  <label class="control-label col-md-2"  for="ac_holder_name">Account Holder Name<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<input  type="text" name="ac_holder_name"  placeholder="" required=required class="form-control input-md" >
  </div>
</div>


<div class="form-group row">
 <label class="control-label col-md-2"  for="ac_number">Account Number<span style="color:red">*</span></label>  
  <div class="col-md-4">
    	<input  type="text" name="ac_number"  placeholder="" required=required class="form-control input-md" >
  </div>
</div>
  
<div class="form-group row">
  <label class="control-label col-md-2"  for="ifsc_code">IFSC Code<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<input  type="text" name="ifsc_code"  placeholder="" required=required class="form-control input-md" >
  </div> 
</div>

<div class="form-group row">
<label class="control-label col-md-2"  for="bank_name">Bank Name<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<input type="text" name="bank_name"  placeholder="" required=required class="form-control input-md" >
  </div>
</div>

<div class="form-group row">
<label class="control-label col-md-2"  for="branch_det">Branch Address<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<input type="text" name="branch_det"  placeholder="" required=required class="form-control input-md" >
  </div>
</div>


<div class="form-group" style="margin-left:22%;">
  <label class=" control-label" for="update"></label>
  <div>
    <input type="submit" name="submit" class="btn btn-info" value="Submit" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
    <input type="reset"  name="Reset" class="btn" value="Reset" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
	</div>
</div>
</form>
	
		
	
</div>
<?php // include("footer.inc.php"); ?>
</body>
</html>
