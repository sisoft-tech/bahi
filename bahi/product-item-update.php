<?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/session.php';
include 'include/param.php';
//checksession();

$biz_id = $_SESSION['biz_id'] ;
$uname = $_SESSION['biz_user_name'] ;

$ecomFeature = 'N' ;
$enable_batch="N" ;


date_default_timezone_set('Asia/Kolkata');
$dtm = date("Y/m/d H:i:s"); 

if(isset($_POST['update_id']))
{
	$id=$_POST['update_id'];
	$selectsql="select * from product_item where item_id = '$id'";
	//echo $selectsql ;
	$rs = mysqli_query($conn,$selectsql);
	$rows=mysqli_fetch_array($rs);
	$cur_grp_id = $rows['item_grp_id'];
    $cur_item_type = $rows['item_type'];
    $mrp = $rows['item_mrp'];
	if ($ecomFeature=='Y'){
		$sale_price = $rows['item_sale_price'];
		if ($mrp > $sale_price) 
			$customerSaving = round(((($mrp-$sale_price)/$mrp)*100)) ;
		else
			$customerSaving = 0 ;
	}
}

if(isset($_POST['update'])){
	$id=$_POST['item_id'];
	$item_name= $_POST['item_name'];
	$item_disp_name=$_POST['item_disp_name'];
	
	$item_desc = $_POST['item_desc'] ;
	$grp_id = $_POST['prod_grp_id'];
	$manuf_code = $_POST['manu_code'];
	$bar_code = $_POST['bar_code'];

	$item_type = $_POST['item_type'];
    $item_uom = $_POST['item_uom'] ;
	
	$item_pur_price = $_POST['item_pur_price'] ;
	$item_mrp = $_POST['item_mrp'] ;

	if ($ecomFeature=='Y'){
		$item_sale_price = $_POST['item_sale_price'] ;
		$disp_seq = $_POST['disp_seq'] ;	
	}
	else {
		$item_sale_price = 0 ;
		$disp_seq = 500 ;	
	}
	
	
	$sql="UPDATE `product_item` SET `item_name`='$item_name',`item_disp_name`='$item_disp_name',
	`item_desc`='$item_desc', `item_grp_id`='$grp_id',`manu_code`='$manuf_code',`bar_code`='$bar_code', 
	`item_type`='$item_type', `item_uom`='$item_uom', `item_pur_price`=$item_pur_price, `item_mrp`=$item_mrp,
	`disp_seq`='$disp_seq', `item_sale_price`=$item_sale_price,`update_dtm`='$dtm' 
	 WHERE `item_id`='$id'";


//echo $sql ;

$result=mysqli_query($conn,$sql);

$err= mysqli_errno($conn);
if ($err!=0)
{
	echo $err.":".mysqli_error($conn);
	echo "<br>".$sql ;
	exit(1) ;
}
else
{	
header("location:product-item-manage.php");
}

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
<!--  <link href="css/styles.css" rel="stylesheet" type="text/css" /> -->
  <script src='https://ajax.googleapis.com/ajax/libs/jquery/1.11.3/jquery.min.js'></script>
  
  <script>
function customerSaving(x)
{
	var mrp = x ;
	var sale_price = document.getElementById('item_sale_price').value ;
	var saving = Math.round(((mrp-sale_price)/mrp)*100) ;
	document.getElementById('item_saving').value = saving+'%'	;
//	alert("Product Item Added Successfully....");
}



function remove_file(item_id, pic_id, file_path)
	{
		var status = confirm("Are you sure you want to delete ?" +item_id+":"+ file_path);		
	  if(status==true)
	  {
			var action = "delete";
			//alert("In fetch data");
			var image_tag='#uploaded_image'+pic_id ;

		  
			$.ajax({
				url:"product-item-image-delete.php",
				method:"POST",
				data:{action:action, item_id:item_id, pic:pic_id, file_path:file_path},
				success:function(data)
				{
					alert("In Success:"+data );
					$(image_tag).html("");
				}
			})
			
		}
	}
	 
function upload_file(item_id,pic_id)  // NOT USED as of 16Sep2023
	{
		$('#file2').trigger('click');
		var name = document.getElementById("file2").files[0].name;
 
  var form_data = new FormData();
  var ext = name.split('.').pop().toLowerCase();
  if(jQuery.inArray(ext, ['gif','png','jpg','jpeg']) == -1) 
  {
   alert("Invalid Image File222");
  }
  var oFReader = new FileReader();
  oFReader.readAsDataURL(document.getElementById("file").files[0]);
  var f = document.getElementById("file").files[0];
  var fsize = f.size||f.fileSize;
  if(fsize > 2000000)
  {
   alert("Image File Size is very big");
  }
  else
  {
   	form_data.append("file", document.getElementById('file').files[0]);
	form_data.append("item_id", item_id);
	form_data.append("pic",pic_id);
	 alert ("1111111");
	  
for(let [name, value] of formData) {
  alert(`${name} = ${value}`);
	  
   $.ajax({
    url:"product-item-image-upload.php",
    method:"POST",
    data: form_data,
    contentType: false,
    cache: false,
    processData: false,
    beforeSend:function(){
     $('#uploaded_image').html("<label class='text-success'>Image Uploading...</label>");
    },   
    success:function(data)
    {
     $('#uploaded_image').html(data);
    }
   });
  }
   
		  
	  }
	}
</script>

<script>
$(document).ready(function(){
 $(document).on('change', '#file2', function(){
  var name = document.getElementById("file2").files[0].name;
 
  var form_data = new FormData();
  var ext = name.split('.').pop().toLowerCase();
  if(jQuery.inArray(ext, ['gif','png','jpg','jpeg','bmp']) == -1) 
  {
   alert("20:Invalid Image File.Only files with png, jpg, jpeg,gif,bmp extensions allowed.");
   return ;
  }
  var oFReader = new FileReader();
  oFReader.readAsDataURL(document.getElementById("file2").files[0]);
  var f = document.getElementById("file2").files[0];
  var fsize = f.size||f.fileSize;
  if(fsize > 2000000)
  {
   alert("21:Image File Size is very big");
  }
  else
  {
	var item_id = document.getElementById("item_id").value ;
    form_data.append("file", document.getElementById('file2').files[0]);
	form_data.append("item_id", item_id);
	form_data.append("pic","2");

   $.ajax({
    url:"product-item-image-upload.php",
    method:"POST",
    data: form_data,
    contentType: false,
    cache: false,
    processData: false,
    beforeSend:function(){
     $('#uploaded_image2').html("<label class='text-success'>Image Uploading...</label>");
    },   
    success:function(data)
    {
     $('#uploaded_image2').html(data);
    }
   });
  }
 });
});
</script>


<script>
$(document).ready(function(){
 $(document).on('change', '#file1', function(){
  var name1 = document.getElementById("file1").files[0].name;
 
  var form_data = new FormData();
  var ext1 = name1.split('.').pop().toLowerCase();
  alert(ext1);
  if(jQuery.inArray(ext1, ['gif','png','jpg','jpeg','bmp']) == -1) 
  {
   alert("10:Invalid Image File. Only files with png, jpg, jpeg,gif,bmp extensions allowed.");
   return ;
  }
  var oFReader = new FileReader();
  oFReader.readAsDataURL(document.getElementById("file1").files[0]);
  var fa = document.getElementById("file1").files[0];
  var fasize = fa.size||fa.fileSize;
  if(fasize > 2000000)
  {
   alert("11:Image File Size is very big");
  }
  else
  {
	var item_id = document.getElementById("item_id").value ;
	form_data.append("file", document.getElementById('file1').files[0]);
	form_data.append("item_id", item_id);
	form_data.append("pic","1");
	$.ajax({
    url:"product-item-image-upload.php",
    method:"POST",
    data: form_data,
    contentType: false,
    cache: false,
    processData: false,
    beforeSend:function(){
     $('#uploaded_image1').html("<label class='text-success'>Image Uploading...</label>");
    },   
    success:function(data)
    {
     $('#uploaded_image1').html(data);
    }
   });
  }
 });
});
</script>

</head>
  
<body style="background-color:#ccf2ff">
    <div>
    <?php 
	include 'header.inc.php';
	?>
	</div>	
<div class ="container-fluid" >
	

  <div style="margin-top:100px;">
  <h2 class="text-primary text-center">Update Product Item</h2><br>
  </div>      

<form class="form-horizontal" style="margin-left:10%;" method="POST" >
	<input type="hidden" id="item_id" name="item_id" value="<?php echo $rows['item_id']; ?>">
	
	<div class="form-group row"> 
  <label class="control-label col-md-2"  for="item_name">Item Name<span style="color:red">*</span></label>  
  <div class="col-md-3">
	<input  name="item_name"  placeholder="" required=required class="form-control input-md" type="text" value="<?php echo $rows['item_name']; ?>">
  </div>

  <label class="control-label col-md-2"  for="bar_code">Item Display Name</label>  
  <div class="col-md-2">
	<input  name="item_disp_name" id="item_disp_name"  placeholder="" class="form-control input-md" type="text" value="<?php echo $rows['item_disp_name']; ?>">
  </div>
    
</div>

<div class="form-group row">

  <label class="control-label col-md-2"  for="item_desc">Item Desc<span style="color:red">*</span></label>  
  <div class="col-md-3">
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
    <label class="control-label col-md-2"  for="manu_code">Manufacturer/Brand Name</label>  
  <div class="col-md-2">
	<input  name="manu_code"  placeholder="" required=required class="form-control input-md" type="text" value="<?php echo $rows['manu_code']; ?>">
  </div>

  <label class="control-label col-md-2"  for="bar_code">Bar Code</label>  
  <div class="col-md-2">
	<input  name="bar_code"  placeholder="" class="form-control input-md" type="text" value="<?php echo $rows['bar_code']; ?>">
  </div>
  
</div>

<div class="form-group row"> 
  <label class="control-label col-md-2"  for="item_name">Item Purchase Price<span style="color:red">*</span></label>  
  <div class="col-md-2">
	<input  name="item_pur_price"  placeholder="" required=required class="form-control input-md" type="text" value="<?php echo $rows['item_pur_price']; ?>">
  </div>

	<label class="control-label col-md-2"  for="item_mrp">Item MRP<span style="color:red">*</span></label> 
  <div class="col-md-2">  
	<input  name="item_mrp"  placeholder="" required="required" class="form-control input-md" type="text" id="item_mrp" onchange="customerSaving(this.value)" value="<?php echo $rows['item_mrp']; ?>">
  </div> 
</div>

<div class="form-group row"> 
  <label class="control-label col-md-2"  for="item_type">Item Type<span style="color:red">*</span></label>  
   <div class="col-md-2">
	<select class="form-control" name="item_type" required=required>
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

<?php 
if ($ecomFeature=='Y') {
?>


<div class="form-group row"> 
	<label class="control-label col-md-2"  for="item_sale_price">Item Sale Price<span style="color:red">*</span></label> 
  <div class="col-md-2">  
	<input  name="item_sale_price"  placeholder="" required="required" class="form-control input-md" type="text" id="item_sale_price" value="<?php echo $rows['item_sale_price']; ?>">
  </div> 
  	<label class="control-label col-md-2"  for="item_saving">Customer Saving<span style="color:red">*</span></label> 
  <div class="col-md-2"> 
  	<input  readonly name="item_saving"  placeholder="" required="required" class="form-control input-md" type="text" id="item_saving" value="<?php echo $customerSaving.'%'; ?>">
  </div> 
</div>

<div class="form-group row"> 
	<label class="control-label col-md-2"  for="disp_seq">Display Sequence<span style="color:red">*</span></label>   
	<div class="col-md-2">
		<input  name="disp_seq"  placeholder="" required="required" class="form-control input-md" type="text" id="sw" value="<?php echo $rows['disp_seq']; ?>">
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

<!---- Product Pic 1 -->  
 <label class="control-label col-md-2"  for="prod_pic">Product Pic-1<span style="color:red">*</span></label>  
  <div class="col-md-3">
     
       <span id="uploaded_image1" name="upload1"><img id='profile-img-tag1' src= '<?php echo $rows['img_path1'];?>' alt="No Image" height='200' width='275'></span> 
		
<br><br>
<input style="display: none; visibility: hidden;" id="file1" type="file" name="file1">
<button type="button" id="btnupload1" name="Upload" class="btn btn-warning bt-xs upload" onclick="$('#file1').trigger('click');">Upload1</button>
	
	<button type="button" name="delete" class="btn btn-danger bt-xs delete" onclick="remove_file(<?php echo $rows['item_id']; ?>,1,'<?php echo $rows['img_path1']; ?>')" >Remove</button>
  </div>
   
<!---- Product Pic 2 -->   
    <label class="control-label col-md-2"  for="prod_pic2">Product Pic-2<span style="color:red">*</span></label>  
      <div class="col-md-3">
      
      <span id="uploaded_image2" name="upload2"><img id='profile-img-tag2' src= '<?php echo $rows['img_path2'];?>' alt="No Image" height='200' width='275'></span> 

<br><br>
<input style="display: none; visibility: hidden;" id="file2" type="file" name="file2">
<button type="button" id="btnupload2" name="Upload" class="btn btn-warning bt-xs upload" onclick="$('#file2').trigger('click');">Upload2</button>

	
	
	<button type="button" name="delete" class="btn btn-danger bt-xs delete" onclick="remove_file(<?php echo $rows['item_id']; ?>,2,'<?php echo $rows['img_path2']; ?>')">Remove</button>
 </div> 
</div>
   
<?php
}    // ecomFeature
?>


   
<div class="form-group" style="margin-left:35%;">
  <label class=" control-label" for="update"></label>
  <div>
    <input type="submit" name="update" class="btn btn-info" value="Update" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
     <input type="reset" name="cancel" class="btn btn-default" value="Cancel" onClick="location.href = 'product-item-manage.php';" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
	</div>
</div>
</form>
	<div style="position:absolute; width:100%; left:0; right:0; margin-top:151px;">
		<?php // include("footer.inc.php"); ?>
	</div>
</div>
<script type="text/javascript">
		
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
</script>

</body>

</html>