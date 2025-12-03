<?php
ob_start();
session_start();
include 'include/dbi.php';

$debug = 0 ;
date_default_timezone_set('Asia/Kolkata');
$dtm=date("Y-m-d H:i:s"); 

if(isset($_POST['biz_id']))
{
   $view_id = $_POST['biz_id'];
   $logo_result = mysqli_query($conn, "SELECT * FROM `biz_establishment` where biz_id='$view_id'");
}

if(isset($_POST['delete_logo']))
{
  $logo_id = $_POST['logo_delete_id'];
  $logo_name = $_POST['logo_name'];
  if(!unlink($logo_name)){
		echo "Error in deleting file";
	}
	$logo_qry = "UPDATE biz_establishment SET biz_logo_image_loc='' where biz_id='$logo_id'";
	echo $logo_qry;
	mysqli_query($conn, $logo_qry) or die(mysqli_error($conn));
	echo "<br> ".$logo_name." File Deleted Successfully!";
   //echo "<meta http-equiv='refresh' content='0'>";
}
  
if (isset($_REQUEST['submit_logo'])) {    // File Uploaded 
	$uploadOK = 1 ;	
	$filename = $_FILES['logo_file']['name'];
	
	if (isset($_FILES['logo_file']['name'])){

	$maxLogoFileSize = 200*1024 ;  //50 KB=> 200 KB(27-03-2024)
// Location
	$location = 'logos/';
	$target_file = $location ."L".$view_id.$filename;
 //echo $target_file;
	if (file_exists($target_file)) {
     echo "<br>Logo file already exists.Rewritting";
   }
// file extension
$file_extension = pathinfo($target_file, PATHINFO_EXTENSION);
$file_extension = strtolower($file_extension);

// Valid image extensions
$image_ext = array("jpg","jpeg","png");
$fileSize= $_FILES["logo_file"]["size"] ;
if ( $fileSize > $maxLogoFileSize) {
    echo "Sorry, Logo file is too large($fileSize). Logo File Size Limit:".$maxLogoFileSize;
	$uploadOK = 0;
   }

if(!(in_array($file_extension,$image_ext))){
   echo "Sorry, only jpg,jpeg,png files are allowed.";
	$uploadOK = 0;   
}

$response = 0;
  // Upload file
  echo "uploadOK :".$uploadOK ;
if ($uploadOK == 1) {  
  if(move_uploaded_file($_FILES['logo_file']['tmp_name'],$target_file)){
    echo "<div style='z-index:1;'>The Logo file ". basename( $_FILES["logo_file"]["name"]). " has been uploaded.</div>";
        $logo_file=$_FILES['logo_file']['name'];
       
    $update_sql = "UPDATE `biz_establishment` SET biz_logo_image_loc='$target_file' where biz_id='$view_id'";
	//echo $insert_sql ;
    $result = mysqli_query($conn, $update_sql);
   // echo "<meta http-equiv='refresh' content='0'>";
   // header("Location: bize2-qry.php");
    } else {
        echo "Sorry, there was an error uploading Logo file.";
    }
}
}
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>Manage Business - Manage logo </title>
<link rel="shortcut icon" type="image/icon" href="image/icon-main.png"/>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="description" content="Business Listing for Local Business - Add your business , free business listing  " />
<meta name="keywords" content="Free Business Listing" />
<!-- SCRIPT-->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">

<style type="text/css">
      
.row a{
	text-decoration: none;
	font-size: 16px;	
	font-weight:bold;
	} 
</style>
<script>
function validate_fileupload(input_element)
{
	
    var fileName = document.getElementById('logo_file').value;
	console.log("Validate upload"+fileName);
    var allowed_extensions = new Array("jpg","png","jpeg");
    var file_extension = fileName.split('.').pop(); 
    for(var i = 0; i < allowed_extensions.length; i++)
    {
        if(allowed_extensions[i]==file_extension)
        {
            valid = true; // valid file extension
            return true;
        }
    }
    alert("Invalid file");
    return
}

</script>


</head>
<body>

    <?php 

    include("biz-header.php");

	   ?>

<div class="container">
<div class="row">	
	<div class="col-sm-2" style="margin-top:30px;">
		<button type ="submit" class="btn" onClick="window.location.assign('biz-profile.php')">Back</button>
   </div>
   <div class="col-sm-10">
		<h3 style="text-align: center;"> Business Page - Manage Logo </h3>
	</div>
</div>
<br>


<div class="row">
  <div class="col-lg-3"></div>
  <div class="col-lg-8">
<table class="table">
        <thead>
          <tr>
              <th>Logo File</th>
              <th></th>
              <th>Delete</th>
          </tr>
        </thead>

        <tbody>
          <?php
            $i =1;
           while($row = mysqli_fetch_array($logo_result))
           {
            if($row['biz_logo_image_loc']!='')
            {
            ?>
          <tr>
            <td><?php echo "<img src=".$row['biz_logo_image_loc'].">"; ?></td>
              <td></td>
              <td>
        <form action= "" method="POST" >
          <input type= "hidden" name ="biz_id" value ="<?php echo $view_id; ?>"/>
          <input type= "hidden" name ="logo_name" value ="<?php echo $row['biz_logo_image_loc']; ?>"/>
          <input type= "hidden" name ="logo_delete_id" value ="<?php echo $row['biz_id']; ?>"/>
          <input class="red btn-floating btn-large" name="delete_logo" type ="submit" value="Delete">
        </form>
      </td>
          </tr>
         <?php
          }
          }
         ?>
        </tbody>
 </table>
</div>
</div>

    <form method="POST" name="logo-form" enctype="multipart/form-data" onsubmit ="return validate_fileupload(this);">

          <input type= "hidden" name ="biz_id" value ="<?php echo $view_id; ?>"/>
		
      		<div class="row">
				 <div class="col-lg-3"></div>
				 <div class="input-field col-lg-4">
			             <h4>Upload Logo</h4>
			     </div>
  				 <div class="input-field col-lg-4">
			        <input type="file" name="logo_file" id="logo_file">
			      <div class="file-path-wrapper">
			        <input class="file-path validate" type="text">
			      </div>
				  Only JPG, JPEG and PNG Files Allowed.Size upto 50 KB. 
    			</div>
			</div>
			<br>
      		<div class="row">
				 <div class="col-lg-7"></div>
     			<div class="file-field input-field col-lg-2">
				<button class="btn" type="submit" name="submit_logo"> Submit </button>
				</div>	
               <div class="input-field col-lg-2">
               	<button class="btn" type="reset" name="Reset" onClick="location.href = 'biz-profile.php';">
    				Cancel 		</button> 
               </div>

    		</div>
	</form>	
        
      
</div>
<div><?php // include "biz-footer.php"; ?></div>
</body>
</html>
