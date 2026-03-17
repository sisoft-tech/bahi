<?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/session.php';
//checksession();

//$uname = "info@sisoft.in";
//$biz_id = "99" ;
//$biz_name = "Sisoft Learning" ;

$biz_id = $_SESSION['biz_id'] ;
$uname = $_SESSION['biz_user_name'] ;

if(isset($_GET['update_id']))
{
	$id=$_GET['update_id'];
	$selectsql="select * from product_group where grp_id = '$id'";
	//echo $selectsql ;
	$rs = mysqli_query($conn,$selectsql);
	$rows=mysqli_fetch_array($rs);
	$cur_pgrp_id = $rows['parent_grp_id'];
//	echo "Current Group ID". $cur_pgrp_id ;
}

if(isset($_POST['update'])){
	$parent_grp_id=$_POST['parent_grp_id'];
	$group_name= $_POST['group_name'];
	$group_desc = $_POST['group_desc'];
	$hsn_code = $_POST['hsn_code'];
	$gst = $_POST['gst'];
	$cess = $_POST['cess'];

$sql="UPDATE product_group set parent_grp_id='$parent_grp_id', grp_name = '$group_name',grp_desc = '$group_desc',hsn_code ='$hsn_code',
gst = '$gst', cess = '$cess' where  grp_id = '$id'";
$result=mysqli_query($conn,$sql);

header("location:product-group-manage.php");
$err= mysqli_error($conn);
echo $err;
}
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<link rel="icon" type="image/png" href="images/icon.png" />
<title>Product Group Update</title>

<meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
  
<body style="background-color:#ccf2ff">
<div class ="container-fluid" >   	<!-- body -->
<div>
    <?php 
	// include 'top-header.php'; 
	include 'header.inc.php';
	?>
	</div>	

  <div style="margin-top:100px;">
  <h2 class="text-primary text-center">Update Product Group</h2><br>
</div>      

<form class="form-horizontal" style="margin-left:27%;" method="POST" onSubmit="return validateForm(this)">
<div class="form-group row">
	<label class="control-label col-md-2"  for="parent_grp_id">Parent Group Id<span style="color:red">*</span></label>  
   <div class="col-md-4">
	<select class="form-control" name="parent_grp_id" id="parent_grp_id" required=required>
            <?php 


			$all_grp_name=mysqli_query($conn,"SELECT grp_id, grp_name FROM product_group where biz_id='$biz_id'");
			while($r=mysqli_fetch_array($all_grp_name))
			{
				$g_name=$r['grp_name'];
				$grp_i=$r['grp_id'];
				if ($grp_i == $cur_pgrp_id) { 
				echo "<option value='$grp_i' selected>$g_name</option>" ;
				}
			else{
				 echo "<option value='$grp_i'>$g_name</option>" ;
				}

			} 
			if ($cur_pgrp_id == 0){
			echo "<option value='0' selected>Primary</option>" ;	
			}
			else
			{
				echo "<option value='0'>Primary</option>" ;	
			}
			
			?>
	</select>
  </div>
</div> 
<div class="form-group row">
  <label class="control-label col-md-2"  for="group_name">Group Name<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<input  name="group_name"  placeholder="" required=required class="form-control input-md" type="text" value="<?php echo $rows['grp_name'];?>">
  </div>
</div>


<div class="form-group row">
 <label class="control-label col-md-2"  for="group_desc">Group Description<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<textarea  name="group_desc"  placeholder="" required=required class="form-control input-md"><?php echo $rows['grp_desc'];?></textarea>
  </div>
</div>
  
<div class="form-group row">
  <label class="control-label col-md-2"  for="hsn_code">HSN Code<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<input  name="hsn_code"  placeholder="" required=required class="form-control input-md" type="text" value="<?php echo $rows['hsn_code'];?>">
  </div> 
</div>

<div class="form-group row">
<label class="control-label col-md-2"  for="gst">GST %<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<input  name="gst" min="0"  required=required class="form-control input-md" type="number" value="<?php echo $rows['gst'];?>">
  </div>
</div>

<div class="form-group row">
<label class="control-label col-md-2"  for="cess">CESS %<span style="color:red">*</span></label>  
  <div class="col-md-4">
	<input  name="cess" min="0"  required=required class="form-control input-md" type="number" value="<?php echo $rows['cess'];?>">
  </div>
</div>


<div class="form-group" style="margin-left:22%;">
  <label class=" control-label" for="update"></label>
  <div>
    <input type="submit" name="update" class="btn btn-info" value="Update" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
     <input type="reset" name="cancel" class="btn btn-default" value="Cancel" onClick="location.href = 'product-group-manage.php';" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
	</div>
</div>
</form>
	
		
	
</div>
<?php // include("footer.inc.php"); ?>
</body>
</html>