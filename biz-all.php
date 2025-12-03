<?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/mybiz-plib.php';
include 'include/session.php';
include 'include/param-pos.php';

checksession();
$username_head = $_SESSION['login'];

$debug = 0 ;

  $if_login = $username_head ;

  $base_qry = "SELECT * from biz_establishment ORDER BY user_added, biz_name";
  if ($debug) echo $base_qry ;

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml">

<head>
<title> Euphoria Bahi - All Business</title>
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


<div class="container">

<div class="row" style="margin-top:30px;">

		<div class="col-lg-8"><h3> All Businesses </h3> </div>
			<div class="col-lg-4"> </div>

	</div>


<table class="table table-striped" >
        <thead>
          <tr> 
              <th>#</th>
			  <th>Owner User </th>
			  <th>Business ID</th> 
			  <th>Business Name</th>
			  <th>Buisness Category</th>
<!--              <th>Buisness Details</th>              -->
              <th>Phone</th>
              <th>Email<br>Website</th>
              <th>Address</th>
<!--              <th>Manage Profile</th> -->
              <th>Billing Desktop</th>
<!--			  <th>e-Commerce Orders</th> -->
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
      <td><?php echo $row['user_added'] ; ?></td>
      <td><?php echo $row['biz_id'] ; ?></td>
      <td><?php 
	  $logo_img_loc = $row['biz_logo_image_loc'];
	  if ($logo_img_loc != NULL)
	     echo "<img src='$logo_img_loc' width='50px'>" ;
		 echo $row['biz_name']; ?></td>
      <td><?php $bcat_id=$row['bcat_id'] ; $bcat_name=getCategoryName($conn, $bcat_id); echo $bcat_name;  ?></td>
<!--      <td><?php echo $row['biz_details'] ; ?></td>  -->
      <td><?php echo $row['biz_phone1']."<br>".$row['biz_phone2']; ?></td>
      <td><?php echo $row['biz_email']."<br>".$row['biz_website']; ?></td>     
      <td><?php echo $row['biz_area']."<br>".$row['biz_city']; ?></td>
<!--      
      <td>
        <form action = "biz-profile.php" method="POST" >
          <input type = "hidden" name ="update_id" value ="<?php echo $row['biz_id']; ?>"/>
          <button class="blue btn-floating btn-large" type ="submit"><span class="material-symbols-outlined">
settings
</span> </button>
        </form>
      </td>
-->
      <td style="text-align:center;">
   		<form action = "pos/pos-index.php" method="POST" >
          <input type = "hidden" name ="biz_id" value ="<?php echo $row['biz_id']; ?>"/>
          <input type = "hidden" name ="user_email" value ="<?php echo $if_login; ?>"/>
          <button class="btn-floating btn-large" type ="submit" name="OWNER_POS"><span class="material-symbols-outlined">
point_of_sale
</span> </button>
        </form>
      </td>
<!--
       <td style="text-align:center;">
		
				<form action = "pos/ecom-index.php" method="POST" >
					<input type = "hidden" name ="biz_id" value ="<?php echo $row['biz_id']; ?>"/>
					<input type = "hidden" name ="user_email" value ="<?php echo $if_login; ?>"/>
					<button class="btn-floating btn-large" type ="submit" name="OWNER_POS">
					<span class="material-symbols-outlined">
shopping_cart_checkout
</span>
					</button>
				</form>
		
       </td>
-->	   
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