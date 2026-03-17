<?php
ob_start();
session_start();

include 'include/dbi.php';
include 'include/PDOConfig.php' ;
include 'include/stock_journal.php' ;
include_once 'include/session.php';

$dtm=getLocalDtm(); 
$login_user = $_SESSION['pos_login'];

$dbh = new PDOConfig() ;

$item_obj = new Item() ; 
$stk_j = new Stock_Journal($dbh);

$biz_id = $_POST['biz_id'] ;
$item_id = $_POST['item_id'];
$adj_qty = $_POST['item_qty'] ;
$stk_action = $_POST['stk_action'] ;
$narr_text = $_POST['narr_text'] ;

/*** Adjust Quantity **/		
if ($stk_action == "add") {	
	$qty=$item_obj->addItemQty($dbh, $biz_id, $item_id, $adj_qty) ;
	$id=$stk_j->insert_stock_journal($biz_id,$item_id,0,$adj_qty, $qty, $narr_text,0,0,$login_user, $dtm) ;
}
if ($stk_action == "reduce") {
	$qty=$item_obj->reduceItemQty($dbh, $biz_id, $item_id, $adj_qty) ;
	$id=$stk_j->insert_stock_journal($biz_id,$item_id,$adj_qty,0, $qty, $narr_text,0,0,$login_user, $dtm) ;
}	

echo "Action: $stk_action:$biz_id:$item_id:$adj_qty:$narr_text" ;	
?>