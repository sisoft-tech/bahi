<?php
ob_start();
session_start();
include 'include/dbo.php';
include 'include/session.php';
include 'include/param-pos.php';

//include 'include/PDOConfig.php';
$dbh = new dbo() ;
// $biz = new MyBiz() ;
checksession();
$username_head = $_SESSION['login'];


if (isset($_POST['update_id'])){  // call from biz-my-business-manage
	$biz_id = $_POST['update_id'] ;
	$biz_name = $_POST['biz_name'] ;
	$_SESSION['biz_id'] = $biz_id ;
	$_SESSION['biz_name'] = $biz_name ;
}
else{ 
	$biz_id = $_SESSION['biz_id'];
	$biz_name = $_SESSION['biz_name'];
}
//$biz_name = $biz->getBizName($dbh, $biz_id) ;

$debug = 0 ;

//$if_login = $username_head ;

//$base_qry = "SELECT * from biz_establishment where user_added='$if_login' ORDER BY dtm_added DESC";
//if ($debug) echo $base_qry ;

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml">

<head>
	<title> Sellers Desktop - Manage Business Profile/Settings</title>
	<link rel="shortcut icon" type="image/icon" href="image/icon-main.png"/>
	<meta name="description" content="Business Classifieds/Listing for Local Business  " />
	<meta name="keywords" content="Business Classifieds, Free Business Listing" />
	<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
	<META HTTP-EQUIV="PRAGMA" CONTENT="NO-CACHE">
	<META HTTP-EQUIV="EXPIRES" CONTENT="0">
 
     <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
 
 </head>

 <body>
    <?php  include("biz-header.php"); ?>

    <div class="container">
<div class="row" style="margin-top:30px;">
    <div class="col-sm-2" style="margin-top:30px;">
		<button type ="submit" class="btn" onClick="window.location.assign('biz-mybusiness-manage.php')">Back</button>
   </div>

<div class="col-lg-8"> <h3 style="text-align:center;"> My Businesses Profile/Settings </h3>
					<h4 style="text-align:center;"><?php echo $biz_id.":".$biz_name;?> </h4>
</div>

  <div class="col-lg-2" style="margin-top:30px;">
 </div>

</div>

<div class="row" style="margin-top:30px;">
  <div class="col-lg-2" >
 </div>

<div class="col-lg-8">
<ul style="list-style-type:none">
	<li>  
			<form action = "biz-mybusiness-update.php" method="POST" >
			  <input type = "hidden" name ="update_id" value ="<?php echo $biz_id ; ?>"/>
			  <button type ="submit"> Update Business Profile </button>
			</form>


	</li>
	<li>         
			<form action = "biz-file-upload.php" method="POST" >
			  <input type = "hidden" name ="biz_id" value ="<?php echo $biz_id ; ?>"/>
			  <button type ="submit">Update Business Logo </button>
			</form> </li>
			
			
	<li> 

	<br> Not To be used Yet <br>
	<form action = "biz-addresses.php" method="POST" >
			  <input type = "hidden" name ="biz_id" value ="<?php echo $biz_id ; ?>"/>
			  <button type ="submit">Update Business Addresses </button>
			</form></li>
	<li> 

	<br> Not To be used Yet <br>
	<form action = "biz-bank-accounts.php" method="POST" >
			  <input type = "hidden" name ="biz_id" value ="<?php echo $biz_id ; ?>"/>
			  <button type ="submit">Update Business Bank Accounts </button>
			</form>
			 </li>
	<li> Upload/Email PAN, GSTIN, Cancelled Cheque, Address Proof </li>
	<li> Review and Approve the Seller Account </li>

	<li>         
			<form action = "biz-seed-data.php" method="GET" >
			  <input type = "hidden" name ="biz_id" value ="<?php echo $biz_id ; ?>"/>
			  <button type ="submit">Update System Groups and Ledgers </button>
			</form> 
	</li>
		
</ul>		


</div>

  <div class="col-lg-2"> </div>

</div>

    </div>
      <div><?php //include "biz-footer.php"; ?></div>
    </body>
</html>