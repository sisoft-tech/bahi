<?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/mybiz-plib.php';
include 'include/session.php';
include 'include/param-pos.php';
include 'include/PDOConfig.php';

$ASSIGNED_LICENSE = 7 ;

$dbh = new PDOConfig() ;


//checksession();
//$username_head = $_SESSION['login'];

$debug = 0 ;

$base_qry = "SELECT * from biz_admin_users ORDER BY created_dtm DESC";
if ($debug) echo $base_qry ;

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml">

<head>
	<title> Euphoria Bahi - Registered Users</title>
	<link rel="shortcut icon" type="image/icon" href="image/icon-main.png"/>
	<meta name="description" content="Business Classifieds/Listing for Local Business  " />
	<meta name="keywords" content="Business Classifieds, Free Business Listing" />
	<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
	<META HTTP-EQUIV="PRAGMA" CONTENT="NO-CACHE">
	<META HTTP-EQUIV="EXPIRES" CONTENT="0">
  
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
	<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">  
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />   
  
	
    <style>

.row a{
  text-decoration: none;
  font-size: 16px;  
  font-weight:bold;
}

</style>
</head>

<body>
    <?php include("biz-header.php"); ?>
<!--
<div class="container-fluid" style="background:#5bc0de; color:#fff ; padding:15px 0;">
	<div class="container">
			<div style="float:left; padding-top:5px;"> <span class="dropdown" style="font-size:25px;">Business User Management</span> </div>
	</div>
</div><!--heading-->       


<div class="container">
<div class="row" style="margin-top:10px;">

	<div class="col-lg-8"><h3> Registered Users </h3> </div>

	<div class="col-lg-2">
		<button type ="submit" onClick="window.location.assign('user-reg.php')">
      Add User    </button> 
 </div>

	<div class="col-lg-2"> Assigned License: <?php echo $ASSIGNED_LICENSE; ?> </div>

</div>


<div class="container">
<table class="table table-striped" >
        <thead>
          <tr> 
              <th>#</th>
			  <th>User Email</th> 
			  <th>User Name</th>
			  <th>User Phones </th>
              <th>Address</th>
              <th>Status</th>
              <th>Creation Dtm</th>

              <th>Biz Count</th>
              <th>Biz Names</th>
        <!--      <th>Billing Start Date</th> -->
          </tr>
        </thead>

        <tbody>
         
            <?php
            $i=1;
            $result=mysqli_query($conn, $base_qry);

          while($row = mysqli_fetch_array($result))
           {
 ?>
            <tr>
      
      <td><?php echo $i; ?></td>
      <td><?php echo $row['admin_email'] ; ?></td>
      <td><?php echo $row['admin_name']; ?></td>
      <td><?php echo $row['admin_phone'] ; ?><br><?php echo $row['admin_phone2'] ; ?></td>
      <td><?php echo $row['admin_addr']; ?></td>     
      <td><?php echo $row['status']; ; ?></td>
      <td><?php echo $row['created_dtm']; ; ?></td>
      
      <td style="text-align:center;">
			<?php 			
			$biz_email = $row['admin_email'] ; 
			$base_qry = "SELECT biz_id, biz_name from biz_establishment where user_added='$biz_email'";
			$pdo_stmt=$dbh->query($base_qry) ;
			$num_biz = $pdo_stmt->rowCount(); 
			echo $num_biz ;	
			?>
      </td>

      <td style="text-align:left;">
	  <?php
	  while ($row = $pdo_stmt->fetch(PDO::FETCH_ASSOC)){
		  $biz_id = $row["biz_id"] ;
		  $encoded_biz_id = base64_encode($biz_id) ;
		  
		  //printf("%40s:",$row["biz_name"]) ;
		  echo $row["biz_name"];
		  $x = strlen($row["biz_name"]);
		  // echo ":$x:" ;
		  for ($y=$x; $y<20; $y++) echo "&nbsp;";
		  echo ":" ;
		  $href="biz-stat.php?biz_id='$encoded_biz_id'" ;
		  echo '<a href="'.$href.'">';
		  echo "<button type='button'>View Stat:$biz_id</button></a>" ;
		  echo "<br>" ;
	  }
	  ?>
      </td>

       <td style="text-align:center;">
				
       </td>
          </tr>
          <?php  
          $i++; 
        } 
?>
        </tbody>
      </table>

    </div>
      <div><?php //include "biz-footer.php"; ?></div>
    </body>
    </html>