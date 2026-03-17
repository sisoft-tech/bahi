<?php
ob_start();
session_start();
include 'include/dbi.php';

$Product_Image_Location="../images-products/" ;

//upload.php
$biz_id = $_SESSION['biz_id'] ;

if($_FILES["file"]["name"] != '')
{
 $item_id = $_POST['item_id'];
 $pic_num = $_POST['pic'];
 $test = explode('.', $_FILES["file"]["name"]);
 $ext = end($test);
 
 $len = strlen($test[0]) ;
 if ($len < 6)
		$short_name= substr($test[0],0,$len) ;
 else
		$short_name= substr($test[0],0,6) ;

 $name = $biz_id."_".$item_id."_"."2"."_".rand(100, 999) ."_".$short_name. '.' . $ext;  // 2 is Second pic here
 
 $location = $Product_Image_Location . $name;  
 
 move_uploaded_file($_FILES["file"]["tmp_name"], $location);
 
 echo '<img src="'.$location.'" height="150" width="225" class="img-thumbnail" />';

 if ($pic_num ==1){
		$query = "update product_item set img_path1='$location', item_pic1='$name' where  item_id = $item_id";
		//echo $query ;
		mysqli_query($conn, $query);
 }
  if ($pic_num ==2){
		$query = "update product_item set img_path2='$location', item_pic2='$name' where  item_id = $item_id";
		//echo $query ;
		mysqli_query($conn, $query);		
 }
}
?>
    
   