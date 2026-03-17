<?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/session.php';
include 'include/param.php';
include 'include/PDOCon.php' ;
include 'include/stock_journal.php' ;

checksession();

$ecomFeature = 'N' ;
$enable_batch="N" ;

$Product_Image_Location="../images-products/" ;
$biz_id = $_SESSION['biz_id'] ;
$uname = $_SESSION['biz_user_name'] ;

include 'company-info.php' ;

date_default_timezone_set('Asia/Kolkata');
$dtm = date("Y/m/d H:i:s"); 

if(isset($_POST['Add_Product']))
{
	$item_name=$_POST['item_name'];
	$item_disp_name=$_POST['item_disp_name'];
	
	$item_desc=$_POST['item_desc'];
	$prod_group=$_POST['product_gp'];
	$manu_code= $_POST['manu_code'];
	$bar_code = $_POST['bar_code'] ;
	
	$item_type=$_POST['item_type'];
	$item_uom = $_POST['item_uom'] ;
	
	$item_pur_price = $_POST['item_pur_price'] ;
	$item_mrp = $_POST['item_mrp'] ;
	$avail_qty = $_POST['avail_qty'] ;
	$item_sale_price = $_POST['item_sale_price'] ;

	$batch_info_available = "N" ;

if(isset($_POST['cb_batch_info']))
{
	$batch_info_available = "Y" ;
}
	
if ($ecomFeature == 'Y') {
	$item_tax = $_POST['item_tax'];
	$disp_seq = $_POST['disp_seq'] ;
}
else
{
	$item_tax = 'NA';
	$disp_seq = 500 ;
}
	
    $created_by=$uname;
	
	$insert_qry="INSERT INTO `product_item`(`biz_id`, `item_name`,`item_disp_name`, `item_desc`, `item_grp_id`, `manu_code`, `batch_info_available`, `bar_code`, `item_type`, `item_uom`,`item_pur_price`, `item_sale_price`,`item_mrp`,`item_tax`,`avail_qty`, `disp_seq`, `created_dtm`, `created_by`) 
	VALUES ('$biz_id','$item_name','$item_disp_name','$item_desc','$prod_group','$manu_code','$batch_info_available', '$bar_code',        '$item_type', '$item_uom','$item_pur_price','$item_sale_price','$item_mrp','$item_tax','$avail_qty','$disp_seq', '$dtm','$created_by') " ;

	echo $insert_qry;
    //if ($debug)  echo $insert_qry ;	
	$result= mysqli_query($conn,$insert_qry) ;
	if ($result==false){
		$error=mysqli_error($conn) ;
		echo "<BR>Error in Insert Add Product Item".$error ;
		die($error) ;
	}
	$item_id = mysqli_insert_id($conn) ;

if(isset($_POST['cb_batch_info']))
{
$batch_no = $_POST['batch_no'] ;
$mfg_date = $_POST['mfg_date'] ;
$expiry_date = $_POST['expiry_date'] ;
$batch_qty = $_POST['batch_qty'] ;

$insert_item_batch_qry = "INSERT INTO `product_item_batch_details`(`biz_id`, `item_id`, `batch_no`, `mfg_dt`, `exp_dt`, `qty`, `created_dtm`, `created_by`) VALUES ('$biz_id', '$item_id','$batch_no', '$mfg_date', '$expiry_date', '$batch_qty', '$dtm', 
'$created_by')" ;

if ($debug)  echo $insert_item_batch_qry ;	

$result= mysqli_query($conn,$insert_item_batch_qry) ;
}


if ($ecomFeature == 'Y') {	
    $pic1=$_FILES['prod_pic'];
    $file_name1 = $pic1['name'];
	$test = explode('.', $file_name1);
	$len = strlen($test[0]) ;
	if ($len < 6)
		$short_name= substr($test[0],0,$len) ;
	else
		$short_name= substr($test[0],0,6) ;
	$ext = end($test);
	$name1 = $biz_id."_".$item_id."_"."1"."_".rand(100, 999) ."_".$short_name. '.' . $ext;  // 1 is first pic here
	$location1 = $Product_Image_Location . $name1;  
    $upload_file_name1 = $pic1['tmp_name'];
    move_uploaded_file($upload_file_name1, $location1);


    $pic2=$_FILES['prod_pic2'];
    $file_name2 = $pic2['name'];
	$test = explode('.', $file_name2);
	$len = strlen($test[0]) ;
	if ($len < 6)
		$short_name= substr($test[0],0,$len) ;
	else
		$short_name= substr($test[0],0,6) ;

	$ext = end($test);
	$name2 = $biz_id."_".$item_id."_"."2"."_".rand(100, 999) ."_".$short_name. '.' . $ext;  // 2 is Second pic here
	$location2 = $Product_Image_Location . $name2;  
    $upload_file_name2 = $pic2['tmp_name'];
    move_uploaded_file($upload_file_name2, $location2);

	$query = "update product_item set img_path1='$location1', item_pic1='$name1', img_path2='$location2', item_pic2='$name2' where  item_id = $item_id";
		//echo $query ;
	mysqli_query($conn, $query);
}	

// Add Opening Stock in Stock Journal - Date : 27-March-2024
	$dbh = new PDOCon() ;
	$stk_j = new Stock_Journal($dbh);
	$id=$stk_j->insert_stock_journal($biz_id,$item_id,0,0, $avail_qty, "Opening Stock:$avail_qty",0,0,$created_by, $dtm) ;

	header("Location: product-item-manage.php");	
}
 
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<link rel="icon" type="image/png" href="images/icon.png" />
<title>Product Item Add</title>

<meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">

  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
  
  <style>
	#selectedFiles img {
		max-width: 125px;
		max-height: 125px;
		float: left;
		margin-bottom:10px;
	}
</style>
  
<script type="text/javascript">
function validateForm()
{
	alert("Product Item Added Successfully....");
}

function upd_item_display_name()
{
	if (document.getElementById('item_disp_name').value == ""){
		document.getElementById('item_disp_name').value =  document.getElementById('item_name').value
	}
}

function customerSaving()
{
//	var mrp = x ;
	var mrp = document.getElementById('item_mrp').value ;
	var sale_price = document.getElementById('item_sale_price').value ;
	
	var saving = Math.round(((mrp-sale_price)/mrp)*100) ;
	document.getElementById('item_saving').value = saving+'%'	;
//	alert("Product Item Added Successfully....");
}

</script>

<script>
	var selDiv = "";
		
	document.addEventListener("DOMContentLoaded", init, false);
	
	function init() {
		document.querySelector('#files').addEventListener('change', handleFileSelect, false);
		selDiv = document.querySelector("#selectedFiles");
	}
		
	function handleFileSelect(e) {
		
		if(!e.target.files || !window.FileReader) return;

		selDiv.innerHTML = "";
		
		var files = e.target.files;
		var filesArr = Array.prototype.slice.call(files);
		filesArr.forEach(function(f) {
			var f = files[i];
			if(!f.type.match("image.*")) {
				return;
			}

			var reader = new FileReader();
			reader.onload = function (e) {
				var html = "<img src=\"" + e.target.result + "\">" + f.name + "<br clear=\"left\"/>";
				selDiv.innerHTML += html;				
			}
			reader.readAsDataURL(f); 
		});
		
	}
	
function goBack() {
  window.history.back();
}

function toggleBatchInfo(cb_batch_info) {
	
	var x = document.getElementById("batch_info");
	if (cb_batch_info.checked) {
		x.style.display = "block";
	} else {
		x.style.display = "none";
	}

}

	</script>
	</head>

<body>
<div class ="container-fluid" >   	<!-- body -->
	<div>
    <?php 
	include 'header.inc.php';
	?>
	</div>

  <div style="margin-top:100px;">
  <h2 class="text-primary text-center">Add Product Item</h2><br>
</div>      

<form class="form-horizontal" style="margin-left:10%;" method="POST"  enctype="multipart/form-data">
<div class="form-group row"> 
  <label class="control-label col-md-2"  for="item_name">Item Name<span style="color:red">*</span></label>  
  <div class="col-md-3">
	<input  name="item_name"  id="item_name" onChange="upd_item_display_name()" required=required class="form-control input-md" type="text">
  </div>
  
  <label class="control-label col-md-2"  for="bar_code">Item Display Name</label>  
  <div class="col-md-2">
	<input  name="item_disp_name" id="item_disp_name"  placeholder="" class="form-control input-md" type="text">
  </div>
  
</div>

<div class="form-group row">
  <label class="control-label col-md-2"  for="item_desc">Item Desc<span style="color:red">*</span></label>  
  <div class="col-md-6">
	<input  name="item_desc"  placeholder="" required=required class="form-control input-md" type="text">
  </div>

</div>

<div class="form-group row">
  
  <label class="control-label col-md-2"  for="product_gp">Product Group<span style="color:red">*</span></label>  
  <div class="col-md-2">
	<select class="form-control" name="product_gp" required=required>
		<?php 
		echo "<option value=''></option>" ;	
		$grp_qry=mysqli_query($conn,"SELECT * from product_group where biz_id='$biz_id'");
	while($row = mysqli_fetch_array($grp_qry))
	{
	$grp=$row['grp_name'];
	$grp_id=$row['grp_id'];
		echo "<option value='$grp_id'>$grp</option>" ;
	}
		?>
	</select>
  </div>

  <label class="control-label col-md-2"  for="item_uom">Unit of Measurement<span style="color:red">*</span></label>  
  <div class="col-md-2">
	<select class="form-control" name="item_uom" required=required>
		<?php 
			echo "<option value=''></option>" ;	
			$uom_qry=mysqli_query($conn,"SELECT * from product_uom where biz_id = $biz_id");
			while($row = mysqli_fetch_array($uom_qry))
			{
				$uom_cd=$row['uom_cd'];
				$uom_name=$row['uom_desc'];
				echo "<option value='$uom_cd'>$uom_name</option>" ;
			}
		?>
	</select>
  </div>
</div>

<div class="form-group row">   
  <label class="control-label col-md-2"  for="item_type">Item Type</label>  
   <div class="col-md-2">
	<select class="form-control" name="item_type" >
		<?php 
	 for ($i=0;$i<count($lov_item_type); $i++)
	 {
		 echo "<option value='$lov_item_type[$i]'>$lov_item_type[$i]</option>" ;
	 }
		?>
	</select>
  </div>
  
  <label class="control-label col-md-2"  for="bar_code">Bar Code</label>  
  <div class="col-md-2">
	<input  name="bar_code"  placeholder="" class="form-control input-md" type="text">
  </div>
</div>


<h4> Price Info </h4> 

<div class="form-group row"> 

  <label class="control-label col-md-2"  for="item_name">Item Purchase Price<span style="color:red">*</span></label>  
  <div class="col-md-2">
	<input  name="item_pur_price"  placeholder="" required=required class="form-control input-md" type="number" value="0">
  </div>

	<label class="control-label col-md-2"  for="item_mrp">Item MRP<span style="color:red">*</span></label> 
  <div class="col-md-2">  
	<input  name="item_mrp"  placeholder="" required="required" class="form-control input-md" type="number" value="0" id="item_mrp" onchange="customerSaving()">
  </div> 
  
</div>


<div class="form-group row"> 
	<label class="control-label col-md-2"  for="item_sale_price">Item Sale Price</label> 
  <div class="col-md-2">  
	<input  name="item_sale_price"  placeholder="" class="form-control input-md" type="number" id="item_sale_price" value="0" onchange="customerSaving()">
  </div> 

	<label class="control-label col-md-2"  for="item_saving">Customer Saving</label> 
  <div class="col-md-2"> 
  	<input  readonly name="item_saving"  placeholder=""  class="form-control input-md" type="text" id="item_saving">
  </div> 

</div>


<h4> Inventory Info </h4> 

<div class="form-group row"> 

  	<label class="control-label col-md-2"  for="avail_qty">Opening Stock Quantity<span style="color:red">*<br>Can't be changed</span></label>   
	<div class="col-md-2">
		<input  name="avail_qty"  placeholder="" required="required" class="form-control input-md" type="text" id="avail_qty" value="0" >
	</div> 

  <label class="control-label col-md-2"  for="manu_code">Manufacturer/Brand Name</label>  
  <div class="col-md-2">
	<input  name="manu_code"  placeholder="" class="form-control input-md" type="text">
  </div>
<?php if ($enable_batch=="Y") { ?>
	Add Batch Information
	<input   type="checkbox" name="cb_batch_info" id="cb_batch_info" class="input-md" onchange="toggleBatchInfo(this)">
<?php } ?>
</div>

<div id="batch_info" style="display:none;">
<div class="form-group row"> 
  	<label class="control-label col-md-2"  for="batch_no">Batch Number</label>   
	<div class="col-md-2">
		<input  name="batch_no"  placeholder=""  class="form-control input-md" type="text" id="batch_no" >
	</div> 

  <label class="control-label col-md-2"  for="mfg_date">Manufacturing Date</label>  
  <div class="col-md-2">
	<input  name="mfg_date"  placeholder="" class="form-control input-md" type="date">
  </div>
</div>

<div class="form-group row"> 
  	<label class="control-label col-md-2"  for="expiry_date">Expiry Date</label>   
	<div class="col-md-2">
		<input id="expiry_date" name="expiry_date"  class="form-control input-md" type="date"  >
	</div> 
<div style="display:none;">
  <label class="control-label col-md-2"  for="batch_qty">Quantity</label>  
  <div class="col-md-2">
	<input  name="batch_qty"  placeholder="" class="form-control input-md" type="text" value="0">
  </div>
</div>  
</div>
</div>



<?php 
if ($ecomFeature=='Y') {
?>

<div class="form-group row">  	
<label class="control-label col-md-2"  for="disp_seq">Display Sequence</label>   
<div class="col-md-2">
	<input  name="disp_seq"  placeholder=""  class="form-control input-md" type="text" id="disp_seq" value="500">
  </div>

	<label class="control-label col-md-2"  for="item_tax">Tax</label> 
	<div class="col-md-2"> 
		<select class="form-control" name="item_tax" >
			<option value='I'>Including Tax</option>
			<option value='E'>+ GST Extra</option>
		</select>  
	</div> 
</div>


<div class="form-group row">
 <label class="control-label col-md-2"  for="prod_pic">Product Pic-1<span style="color:red">*</span></label>  
  <div class="col-md-3">
	<input type="file" id="files" name="prod_pic" accept="image/*">
	<img src="" id="profile-img-tag" width="200px"   />
  </div>

 <label class="control-label col-md-2"  for="prod_pic2">Product Pic-2<span style="color:red">*</span></label>  
  <div class="col-md-3">
	<input type="file" id="files1" name="prod_pic2" accept="image/*">
	<img src="" id="profile-img-tag1" width="200px" />
  </div>
</div>
<?php
}    // ecomFeature
?>

<div class="form-group" style="margin-left:29%;">
  <label class=" control-label" for="upload"></label>
  <div>
    <input type="submit" name="Add_Product" class="btn btn-info" value="Add Product" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
	 <input type="reset"  name="Cancel" class="btn" value="Cancel" onclick="goBack()"  style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
	</div>
</div>
</form>	
</div>


<script type="text/javascript">
    function readURL(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            
            reader.onload = function (e) {
                $('#profile-img-tag').attr('src', e.target.result);
            }
            reader.readAsDataURL(input.files[0]);
        }
    }
    $("#files").change(function(){
        readURL(this);
    });
	
	function readURL2(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            
            reader.onload = function (e) {
                $('#profile-img-tag1').attr('src', e.target.result);
            }
            reader.readAsDataURL(input.files[0]);
        }
    }
    $("#files1").change(function(){
        readURL2(this);
    });
</script>

<?php // include("footer.inc.php"); ?>

</body>
</html>