<?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/session.php';
include 'include/param.php';
//checksession();

$biz_id = $_SESSION['biz_id'] ;
$uname = $_SESSION['biz_user_name'] ;

  if(isset($_POST['searchbttn']))
{
	$fromDate=$_POST['searchtext1'];
	$toDate=$_POST['searchtext2'];
}
else{
	$fromDate=date('Y-m-d',strtotime("-1 month")) ;
	$toDate=date('Y-m-d',strtotime("1 day"));
}

date_default_timezone_set('Asia/Kolkata');
$dtm = date("Y/m/d H:i:s"); 

$id=$_POST['view_id'];
$item_name = $_POST['item_name'] ;

	$base_qry="select * from prod_stk_journal where biz_id='$biz_id' and item_id = '$id' and created_dtm between '$fromDate' and '$toDate' ORDER BY stk_jrnl_id DESC";
	$result = mysqli_query($conn,$base_qry) ;

//  echo $base_qry ;

	if($result)
	{

?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<link rel="icon" type="image/png" href="images/icon.png" />
<title>Product Item Stock Journal</title>

<meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
  <script src='https://ajax.googleapis.com/ajax/libs/jquery/1.11.3/jquery.min.js'></script>

<style>
table{
	
	width:100%;
	table-layout:fixed;
}
tbody{
	word-wrap: break-word;
}
  tbody:nth-of-type(odd) {
 background: #99d6ff ;
}
th {
 background: #ffb3b3 ;
 font-weight: bold;
}

@media only screen and (max-width: 800px) {
        /* Force table to not be like tables anymore */
        #no-more-tables table,
        #no-more-tables thead,
        #no-more-tables tbody,
        #no-more-tables th,
        #no-more-tables td,
        #no-more-tables tr {
        display: block;
		
        }
         
        /* Hide table headers (but not display: none;, for accessibility) */
        #no-more-tables thead tr {
        position: absolute;
        top: -9999px;
        left: -9999px;
        }
         
        #no-more-tables tr { border: 1px solid #ccc; }
          
        #no-more-tables td {
        /* Behave like a "row" */
        border: none;
        border-bottom: 1px solid #eee;
        position: relative;
        padding-left: 50%;
        white-space: normal;
        text-align:left;
        }
         
        #no-more-tables td:before {
        /* Now like a table header */
        position: absolute;
        /* Top/left values mimic padding */
        top: 6px;
        left: 2px;
		right: 2px;
        width: 55%;
        padding-right: 10px;
        white-space: nowrap;
        text-align:left;
        font-weight: bold;
        }
         
        /*
        Label the data
        */
        #no-more-tables td:before { content: attr(data-title); }
        }
</style>
  


</head>
  
<body style="background-color:#ccf2ff">
    <div>
    <?php 
	 include 'header.inc.php';
	?>
	</div>	
<div class ="container-fluid" >
	<h2 class="text-primary text-center">View Product Items Journal:<?php echo "$item_name($id)";?></h2>

  <div class="row">
    
    <form method="post" >
	<input type = "hidden" name ="view_id" value ="<?php echo $id; ?>"/>
	<input type = "hidden" name ="item_name" value ="<?php echo $item_name; ?>"/>
	<div class="col-sm-1"><a href='product-item-avail' style='border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);'>❮ Back</a>
	</div>
    <div class="col-sm-6"></div>
	<div class="col-sm-2">
	  <strong> From: </strong>
      <input placeholder="yy-mm-dd" name="searchtext1" id="datepick1" type="date" value="<?php echo $fromDate; ?>">
      </div>
		
		<div class="col-sm-2">
		<strong> To: </strong>
		<input placeholder="yy-mm-dd" name="searchtext2"  id="datepick2" type="date" value="<?php  echo $toDate; ?>">
        </div>
    
	   <div class="col-sm-1">
	   <input type="submit" name="searchbttn" value="Go"  />
      </div>
	</form>
  </div>	  

</div> 
	<div id="no-more-tables">
	<table class="table table-bordered table-stripped table-bordered  table-condensed" style="text-align:center; margin-bottom:40px; margin-top:40px;">
	<thead>
		<th style='color:#b30059; text-align:center;'>#</th>
		<th style='color:#b30059; text-align:center;'>Txn Date/time</th>
		<th style='color:#b30059; text-align:center;'>Quantity Used</th>	
		<th style='color:#b30059; text-align:center;'>Quantity Added</th>
		<th style='color:#b30059; text-align:center;'>Available Qty</th>
		<th style='color:#b30059; text-align:center;'>Narration</th>
        <th style='color:#b30059; text-align:center;'>User</th>
	</thead>

	<?php
			$i=1;

			while($row = mysqli_fetch_array($result))
			{
	 ?>	
				<tbody>
				<tr>
					<td data-title="#"><?php echo $i;?></td>
					<td data-title="Product Group"><?php echo $row['created_dtm'] ;?></td>
					<td data-title="Product Name"><?php echo $row['qty_used'] ;?></td>
					<td data-title="Product Type"><?php echo $row['qty_added'] ;?></td>
					<td data-title="Available Qty"><?php echo $row['avail_qty'] ;?></td>
					<td data-title="Narration"><?php echo $row['narr_text'] ;?></td>                   
					<td data-title="User"><?php echo $row['created_by'] ;?></td>                   
				</tr>
				</tbody>
	<?php		

	$i++;	
			   }

	}	
	?>
		
	</table>
	
</div>

</body>

</html>