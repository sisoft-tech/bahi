<?php
include 'include/dbi.php';

$item_id = $_POST['item_id'];
$avail_status = $_POST['avail_status'] ;
if ($avail_status == 'Y')
	$toggle_status = 'N' ;
else
	$toggle_status = 'Y' ;
	
$item_qry="UPDATE product_item set avail_status='$toggle_status' where item_id='$item_id'";
echo $item_qry ;
$result = mysqli_query($conn,$item_qry );

?>