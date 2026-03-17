<?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/session.php';
include 'include/param.php';
//checksession();

date_default_timezone_set('Asia/Kolkata');
$dtm = date("Y/m/d H:i:s"); 

$debug= 1 ;

$biz_id = $_SESSION['biz_id'] ;
$uname = $_SESSION['biz_user_name'] ;


if(isset($_POST['submit']))
{
	$group_id=$_POST['grp_id'];
	$group_name=$_POST['group_name'];
	$group_desc=$_POST['group_desc'];
	$hsn_code= $_POST['hsn_code'];
	$gst=$_POST['gst'];
	$cess=$_POST['cess'];
	
	$created_by=$uname;
	
	$insert_qry="insert into product_group(biz_id, parent_grp_id,grp_name,grp_desc,hsn_code,gst,cess,created_dtm,created_by) values('$biz_id','$group_id','$group_name','$group_desc','$hsn_code','$gst','$cess','$dtm','$created_by') " ;
 
    if ($debug)  echo $insert_qry ;	
	$result= mysqli_query($conn,$insert_qry) ;
	
	if ($result==false){
		$error=mysqli_error($conn) ;
		echo "<BR>Error in Insert Add Product Group".$error ;
		die($error) ;
	}
	header("Location: product-group-manage.php");
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
  
<script type="text/javascript">
function validateForm()
{
	alert("Product Group Added Successfully....");
}
</script>

<body>
<div class ="container-fluid" >   	<!-- body -->
	<div>
    <?php 
	include 'header.inc.php';
	?>
	</div>

  <div style="margin-top:100px;">
  <h2 class="text-primary text-center">Add Product Group</h2><br>
</div>      

<form class="form-horizontal" style="margin-left:27%;" method="POST" onSubmit="return validateForm(this)">
<div class="form-group row">
	<label class="control-label col-md-2"  for="grp_id">Parent Group<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<select class="form-control" name="grp_id" required=required>
		<?php 
		echo "<option value='0'>Primary</option>" ;	
		$grp_qry=mysqli_query($conn,"SELECT grp_id, grp_name from product_group where biz_id='$biz_id'");
	while($row = mysqli_fetch_array($grp_qry))
	{
	$grp=$row['grp_name'];
	$grp_id=$row['grp_id'];

		echo "<option value='$grp_id'>$grp</option>" ;
	}
		?>
	</select>
  </div>
</div> 
<div class="form-group row">
  <label class="control-label col-md-2"  for="group_name">Group Name<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<input  name="group_name"  placeholder="" required=required class="form-control input-md" type="text">
  </div>
</div>


<div class="form-group row">
 <label class="control-label col-md-2"  for="group_desc">Group Description<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<textarea  name="group_desc"  placeholder="" required=required class="form-control input-md"></textarea>
  </div>
</div>
  
<div class="form-group row">
  <label class="control-label col-md-2"  for="hsn_code">HSN Code<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<input  name="hsn_code"  placeholder="" required=required class="form-control input-md" type="text">
  </div> 
</div>

<div class="form-group row">
<label class="control-label col-md-2"  for="gst">GST %<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<input  name="gst" min="0"  required=required class="form-control input-md" type="number" >
  </div>
</div>

<div class="form-group row">
<label class="control-label col-md-2"  for="cess">CESS %<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<input  name="cess" min="0" required=required class="form-control input-md" type="number" value="0.00">
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
