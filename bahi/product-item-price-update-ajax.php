<?php
include 'include/dbo.php';
include_once 'include/session.php';
include 'include/item.php';

$dtm=getLocalDtm(); 

$dbh = new dbo() ;

$item_obj = new Item() ; 

$biz_id = $_POST['p_biz_id'] ;
$agent_id = $_POST['p_agent_id'] ;

$item_id = $_POST['p_item_id'];
$pur_price = $_POST['p_pur_price'] ;
$mrp = $_POST['p_mrp'] ;
$sale_price = $_POST['p_sale_price'] ;

/*** Update Item Price **/		
	$qty=$item_obj->updateItemPrice($dbh, $biz_id, $item_id, $pur_price, $mrp, $sale_price) ;

// To DO: Keep history of Prices for Product Items ---That is why agent ID 

echo "Update Item Price: $biz_id:$item_id:$pur_price:$mrp:$sale_price " ;	
?>