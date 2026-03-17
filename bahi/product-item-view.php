<?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/session.php';
include 'include/param.php';
//checksession();

$biz_id = $_SESSION['biz_id'] ;
$uname = $_SESSION['biz_user_name'] ;

date_default_timezone_set('Asia/Kolkata');
$dtm = date("Y/m/d H:i:s"); 

if(isset($_POST['view_id']))
{
	$id=$_POST['view_id'];
	$selectsql="select * from product_item where item_id = '$id'";
	//echo $selectsql ;
	$rs = mysqli_query($conn,$selectsql);
	$rows=mysqli_fetch_array($rs);
	$cur_grp_id = $rows['item_grp_id'];
    $cur_item_type = $rows['item_type'];
	$pur_price = $rows['item_pur_price'];
    $mrp = $rows['item_mrp'];
	$sale_price = $rows['item_sale_price'];
	if ($mrp > $sale_price) 
		$customerSaving = round(((($mrp-$sale_price)/$mrp)*100)) ;
	else
		$customerSaving = 0 ;
}

?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<link rel="icon" type="image/png" href="images/icon.png" />
<title>Product Item View</title>

<meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<!--  <link href="css/styles.css" rel="stylesheet" type="text/css" /> -->
  <script src='https://ajax.googleapis.com/ajax/libs/jquery/1.11.3/jquery.min.js'></script>
  

  <script>
  /*
function customerSaving(x)
{
	var mrp = x ;
	var sale_price = document.getElementById('item_sale_price').value ;
	var saving = Math.round(((mrp-sale_price)/mrp)*100) ;
	document.getElementById('item_saving').value = saving+'%'	;
}
*/
</script>


</head>
  
<body style="background-color:#ccf2ff">
    <div>
    <?php 
	// include 'top-header.php'; 
	include 'header.inc.php';
	?>
	</div>	
<div class ="container-fluid" >
	

  <div style="margin-top:100px;">
  <h2 class="text-primary text-center">View Product Item</h2><br>
  </div>      

<form class="form-horizontal" style="margin-left:10%;" method="POST" >
	<input type="hidden" id="item_id" name="item_id" value="<?php echo $rows['item_id']; ?>">
	
<div class="form-group row"> 
  <label class="control-label col-md-2"  for="item_name">Item Name<span style="color:red">*</span></label>  
  <div class="col-md-2">
	<input  name="item_name"  placeholder="" required=required class="form-control input-md" type="text" value="<?php echo $rows['item_name']; ?>">
  </div>
  
  <label class="control-label col-md-2"  for="item_disp_name">Item Display Name<span style="color:red">*</span></label>  
  <div class="col-md-3">
	<input  name="item_disp_name"  placeholder="" required=required class="form-control input-md" type="text" value="<?php echo $rows['item_disp_name']; ?>">
  </div> 
</div>
<div class="form-group row"> 

  <label class="control-label col-md-2"  for="item_desc">Item Desc<span style="color:red">*</span></label>  
  <div class="col-md-6">
	<input  name="item_desc"  placeholder="" required=required class="form-control input-md" type="text" value="<?php echo $rows['item_desc']; ?>">
  </div> 
</div>


<div class="form-group row">
  
  <label class="control-label col-md-2"  for="prod_grp_id">Product Group<span style="color:red">*</span></label>  
  <div class="col-md-2">
	<select class="form-control" name="prod_grp_id" required=required>
            <?php 
			$all_grp_name=mysqli_query($conn,"SELECT grp_id, grp_name FROM product_group where biz_id='$biz_id'");
			while($r=mysqli_fetch_array($all_grp_name))
			{
				$g_name=$r['grp_name'];
				$grp_i=$r['grp_id'];
				if ($grp_i == $cur_grp_id) { 
				echo "<option value='$grp_i' selected>$g_name</option>" ;
				}
			else{
				 echo "<option value='$grp_i'>$g_name</option>" ;
				}
			} ?>	
	</select>
  </div>

  <label class="control-label col-md-2"  for="item_uom">Unit of Measurement<span style="color:red">*</span></label>  
  <div class="col-md-2">
	<input  name="item_uom"  placeholder="" required="required" class="form-control input-md" type="text" value="<?php echo $rows['item_uom']; ?>">
  </div>
</div>

<div class="form-group row">

   <label class="control-label col-md-2"  for="manu_code">Manufacturer Part Code</label>  
  <div class="col-md-2">
	<input  name="manu_code"  placeholder="" class="form-control input-md" type="text" value="<?php echo $rows['manu_code']; ?>">
  </div>

  <label class="control-label col-md-2"  for="bar_code">Bar Code</label>  
  <div class="col-md-2">
	<input  name="bar_code"  placeholder="" class="form-control input-md" type="text" value="<?php echo $rows['bar_code']; ?>">
  </div>
</div>


<div class="form-group row"> 
  <label class="control-label col-md-2"  for="item_type">Item Type</label>  
   <div class="col-md-2">
	<select class="form-control" name="item_type" >
		<?php 
	 for ($i=0;$i<count($lov_item_type); $i++)
	 {
		if ($lov_item_type[$i] == $cur_item_type) { 
				        echo "<option value='$lov_item_type[$i]' selected>$lov_item_type[$i]</option>" ;
					}
		else{
				echo "<option value='$lov_item_type[$i]'>$lov_item_type[$i]</option>" ;
			}
	 }
		?>
	</select>
  </div>
</div>


<div class="form-group row"> 
	<label class="control-label col-md-2"  for="item_pur_price">Item Purchase Price</label> 
  <div class="col-md-2">  
	<input  name="item_pur_price"  class="form-control input-md" type="text" id="item_pur_price"  value="<?php echo $rows['item_pur_price']; ?>">
  </div> 

	<label class="control-label col-md-2"  for="item_mrp">Item MRP</label> 
  <div class="col-md-2">  
	<input  name="item_mrp"  placeholder="" required="required" class="form-control input-md" type="text" id="item_mrp" onchange="customerSaving(this.value)" value="<?php echo $rows['item_mrp']; ?>">
  </div> 
<!--  
	<label class="control-label col-md-2"  for="item_saving">Customer Saving<span style="color:red">*</span></label> 
  <div class="col-md-2"> 
  	<input  readonly name="item_saving"  placeholder="" required="required" class="form-control input-md" type="text" id="item_saving" value="<?php echo $customerSaving.'%'; ?>">
  </div> 
-->
</div>

<!---- Product Pic 1 -->  
<!--
 <label class="control-label col-md-2"  for="prod_pic">Product Pic-1<span style="color:red">*</span></label>  
  <div class="col-md-3">
     
       <span id="uploaded_image1" name="upload1"><img id='profile-img-tag1' src= '<?php echo $rows['img_path1'];?>' alt="No Image" height='200' width='275'></span> 
		
<br><br>
</div>
-->   

<!---- Product Pic 2 -->   
<!--
    <label class="control-label col-md-2"  for="prod_pic2">Product Pic-2<span style="color:red">*</span></label>  
      <div class="col-md-3">
      
      <span id="uploaded_image2" name="upload2"><img id='profile-img-tag2' src= '<?php echo $rows['img_path2'];?>' alt="No Image" height='200' width='275'></span> 

		<br><br>
	</div>
 -->
</div>
    
<div class="form-group" style="margin-left:35%;">
  <label class=" control-label" for="update"></label>
  <div>
     <input type="reset" name="cancel" class="btn btn-default" value="Cancel" onClick="location.href = 'product-item-avail.php';" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
	</div>
</div>
</form>
	<div style="position:absolute; width:100%; left:0; right:0; margin-top:151px;">
		<?php // include("footer.inc.php"); ?>
	</div>
</div>

</body>

</html>