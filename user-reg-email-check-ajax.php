<?php
	session_start();
	include("include/dbi.php");

	if(isset($_POST['remail'])){
		$remail = $_POST['remail'];
		$qry = "select admin_name from biz_admin_users where admin_email='$remail'" ;
		$sql = mysqli_query($conn, $qry );
//		echo $qry.":".mysqli_num_rows($sql) ;
		if(mysqli_num_rows($sql)){
			echo '<font size="2px" color="#cc0000"><STRONG>'.$remail.'</STRONG> is already registered.</font>';
		}else{
			echo 'OK';
		}
	}
?>