<?php 
include 'include/dbi.php';
if(isset($_POST["action"]))
{
if($_POST["action"]=="delete")
{
 $prod_item = $_POST['item_id'];
 $pic_num = $_POST['pic'];
 $file_path = $_POST['file_path'] ;

 if ($pic_num ==1){
		$query = "update product_item set img_path1='', item_pic1=''  where  item_id = $prod_item";
		echo $query ;
		mysqli_query($conn, $query);
		
		if(!unlink($file_path)){
				echo "Error in deleting file";
		}
 }
  if ($pic_num ==2){
		$query = "update product_item set img_path2='', item_pic2=''  where  item_id = $prod_item";
		echo $query ;
		mysqli_query($conn, $query);
		
		if(!unlink($file_path)){
				echo "Error in deleting file";
		}
 }
}
}
?>