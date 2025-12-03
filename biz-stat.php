<?php
include 'include/PDOConfig.php';

$dbh = new PDOConfig() ;
$encoded_biz_id = $_GET['biz_id'] ;
$biz_id = base64_decode($encoded_biz_id) ;
$i = 1 ;
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml">

<head>
	<title> Euphoria Retails - Biz Stats</title>
	<link rel="shortcut icon" type="image/icon" href="image/icon-main.png"/>
	<meta name="description" content="Business Statistics  " />
	<meta name="keywords" content="Business Statistics" />
	<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
	<META HTTP-EQUIV="PRAGMA" CONTENT="NO-CACHE">
	<META HTTP-EQUIV="EXPIRES" CONTENT="0">
  
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">  
</head>

<body>
<div class="container-fluid" style="background:#5bc0de; color:#fff ; padding:15px 0;">
	<div class="container">
			<div style="float:left; padding-top:5px;"> <span class="dropdown" style="font-size:25px;">Business Statistics</span> </div>
	</div><!--end-container--> 
</div><!--heading-->       

<div class="container">
<h3> Biz Statistics : <?php echo $biz_id; ?> </h3>
<table class="table table-striped" >
        <thead>
          <tr> 
              <th>#</th>
			  <th>Data Item</th> 
			  <th>Count</th>
          </tr>
        </thead>

        <tbody>        
	      <tr>  
      <td><?php echo $i; $i++ ; ?></td>
      <td>Unit of Measurements</td>
      <td style="text-align:left;">
			<?php 			
			$base_qry = "SELECT count(*) from product_uom where biz_id='$biz_id'";
			//echo $base_qry ;
			$pdo_stmt=$dbh->query($base_qry) ;
			$num_biz = $pdo_stmt->fetchColumn(); 
			echo $num_biz ;	
			?>
      </td>
      </tr>
	
      <tr>  
      <td><?php echo $i; $i++ ; ?></td>
      <td>Product Groups</td>
      <td style="text-align:left;">
			<?php 			
			$base_qry = "SELECT count(*) from product_group where biz_id='$biz_id'";
			//echo $base_qry ;
			$pdo_stmt=$dbh->query($base_qry) ;
			$num_biz = $pdo_stmt->fetchColumn(); 
			echo $num_biz ;	
			?>
      </td>
      </tr>
      <tr>  
      <td><?php echo $i; $i++ ; ?></td>
      <td>Product Items</td>
      <td style="text-align:left;">
			<?php 			
			$base_qry = "SELECT count(*) from product_item where biz_id='$biz_id'";
			//echo $base_qry ;
			$pdo_stmt=$dbh->query($base_qry) ;
			$num_biz = $pdo_stmt->fetchColumn(); 
			echo $num_biz ;	
			?>
      </td>
      </tr>
      <tr>  
      <td><?php echo $i; $i++ ; ?></td>
      <td>Customers</td>
      <td style="text-align:left;">
			<?php 			
			$base_qry = "SELECT count(*) from account_ledger where biz_id='$biz_id' and ac_group_code='customer'";
			//echo $base_qry ;
			$pdo_stmt=$dbh->query($base_qry) ;
			$num_biz = $pdo_stmt->fetchColumn(); 
			echo $num_biz ;	
			?>
      </td>
      </tr>
      <tr>  
      <td><?php echo $i; $i++ ; ?></td>
      <td>Vendors</td>
      <td style="text-align:left;">
			<?php 			
			$base_qry = "SELECT count(*) from account_ledger where biz_id='$biz_id' and ac_group_code='vendor'";
			//echo $base_qry ;
			$pdo_stmt=$dbh->query($base_qry) ;
			$num_biz = $pdo_stmt->fetchColumn(); 
			echo $num_biz ;	
			?>
      </td>
      </tr>
      <tr>  
      <td><?php echo $i; $i++ ; ?></td>
      <td>Sale Vouchers</td>
      <td style="text-align:left;">
			<?php 			
			$base_qry = "SELECT count(*) from table_invoice_header where biz_id='$biz_id' and txn_type='SALES'";
			//echo $base_qry ;
			$pdo_stmt=$dbh->query($base_qry) ;
			$num_biz = $pdo_stmt->fetchColumn(); 
			echo $num_biz ;	
			?>
      </td>
      </tr>
      <tr>  
      <td><?php echo $i; $i++ ; ?></td>
      <td>Purchase Vouchers</td>
      <td style="text-align:left;">
			<?php 			
			$base_qry = "SELECT count(*) from table_invoice_header where biz_id='$biz_id' and txn_type='PURCHASE'";
			//echo $base_qry ;
			$pdo_stmt=$dbh->query($base_qry) ;
			$num_biz = $pdo_stmt->fetchColumn(); 
			echo $num_biz ;	
			?>
      </td>
      </tr>
      <tr>  
      <td><?php echo $i; $i++ ; ?></td>
      <td>Sale Return Vouchers</td>
      <td style="text-align:left;">
			<?php 			
			$base_qry = "SELECT count(*) from table_invoice_header where biz_id='$biz_id' and txn_type='SALES RETURN'";
			//echo $base_qry ;
			$pdo_stmt=$dbh->query($base_qry) ;
			$num_biz = $pdo_stmt->fetchColumn(); 
			echo $num_biz ;	
			?>
      </td>
      </tr>
      <tr>  
      <td><?php echo $i; $i++ ; ?></td>
      <td>Purchase Return Vouchers</td>
      <td style="text-align:left;">
			<?php 			
			$base_qry = "SELECT count(*) from table_invoice_header where biz_id='$biz_id' and txn_type='PURCHASE RETURN'";
			$pdo_stmt=$dbh->query($base_qry) ;
			$num_biz = $pdo_stmt->fetchColumn(); 
			echo $num_biz ;	
			?>
      </td>
      </tr>

        </tbody>
      </table>

    </div>
    </body>
    </html>