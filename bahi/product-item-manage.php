<?php
	ob_start();
	session_start();
	include "include/dbi.php";
	include "include/session.php";
	include "include/param.php";
	checksession();

	date_default_timezone_set('Asia/Kolkata');
	$dtm = date("Y/m/d H:i:s"); 

	$debug= 1 ;

	$biz_id = $_SESSION['biz_id'] ;
	$uname = $_SESSION['pos_login'] ;
	
	if(isset($_POST['delete_id']))
	{
	$id= $_POST['delete_id'];
	$ref_chk_sql = "Select count(*) from table_invoice_details where item_id = '$id'" ;
	echo $ref_chk_sql ;
	$result=mysqli_query($conn,$ref_chk_sql);
	$row=mysqli_fetch_array($result) ;
	$cnt = $row[0] ;
	if ($cnt >0) {
			echo "Delete not allowed for Used Items: Usage count :$cnt" ;
			echo "<script> alert('Item used in invoices ')</script>" ;
	}
	else 
	{
		$query="DELETE FROM product_item WHERE item_id ='$id'";
		mysqli_query($conn,$query);
		header("location:product-item-manage.php");		
		exit;
	}
	}
	$i=1;
	$base_qry="select * FROM product_item where biz_id='$biz_id' ORDER BY created_dtm DESC";
	$result = mysqli_query($conn,$base_qry) ;

	if($result)
	{
?>
<html>
	<head>
	<title>Manage Product Item</title>
	<link rel="icon" type="image/png" href="images/icon.png" />
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>
	<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" type="text/css" rel="stylesheet">
	<meta http-equiv="Content-Type" content="text/html;"/>

<script LANGUAGE="JavaScript">

function confirmDelete(delete_id) {
var msg;
msg= "Are you sure you want to delete the data ? " ;
var agree=confirm(msg);
if (agree)
return true ;
else
return false ;
}
</script>

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
	
	
	<body style="background-color:#f7ece6">
	
	<div class="container col-md-12" >  
 	<!-- body -->
	<div>
    <?php 
		include 'header.inc.php';
	?>
	</div>
	<div style="margin-top:60px;">
	<h2 class="text-primary text-center">Manage Product Items</h2>
	<form action = "product-item-add.php" method="post" style="float:right;">
	<input type="submit" class="btn btn-success" role="button" value="Add Product Item" style="border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
	</form>
</div> 
	<div id="no-more-tables">
	<table class="table table-bordered table-stripped table-bordered  table-condensed" style="text-align:center; margin-bottom:40px; margin-top:40px;">
	<thead>
		<th style='color:#b30059; text-align:center;'>#</th>
		<th style='color:#b30059; text-align:center;'>Product Group</th>
		<th style='color:#b30059; text-align:center;'>Item Name</th>	
		<th style='color:#b30059; text-align:center;'>Item Type</th>
		<th style='color:#b30059; text-align:center;'>Purchase Price</th>
		<th style='color:#b30059; text-align:center;'>Item MRP</th>
		<th style='color:#b30059; text-align:center;'>Date</th>
		<th style='color:#b30059; text-align:center;'>Created By</th>
<!--        <th style='color:#b30059; text-align:center;'>Review Status</th>  -->
        <th style='color:#b30059; text-align:center;'>Update Item</th>
		<th style='color:#b30059; text-align:center;'>Delete Item</th>
	</thead>

	<?php
			  while($row = mysqli_fetch_array($result))
			   {
	 ?>	
				<tbody>
				<tr>
					<td data-title="#"><?php echo $i;?></td>
					<td data-title="Item Group"><?php echo $row['item_grp_id'] ;?></td>
					<td data-title="Item Name"><?php echo $row['item_name'] ;?></td>
					<td data-title="Item Type"><?php echo $row['item_type'] ;?></td>
					<td data-title="Purchase Price"><?php echo $row['item_pur_price'] ;?></td>
					<td data-title="MRP"><?php echo $row['item_mrp'] ;?></td>
					<td data-title="Date"><?php echo $row['created_dtm'] ; ?></td>
					<td data-title="Created By"><?php echo $row['created_by'] ; ?></td>

<!--					<td data-title="Review Status"><?php echo $row['review_status'] ; ?></td> -->

					
					
                    <td data-title="Upload Item">
						<form action = "product-item-update.php" method="POST" >
							<input type = "hidden" name ="update_id" value ="<?php echo $row['item_id']; ?>"/>
							 <input type="submit" class="btn btn-primary" value="Update" style="border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
						</form>
					</td>
                    
					<td data-title="Delete Item">
						<form action = "product-item-manage.php" method="POST" onClick="return confirmDelete(this)">
							<input type = "hidden" name ="delete_id" value ="<?php echo $row['item_id']; ?>"/>
							<input type="submit" class="btn btn-danger" role="button" value="Delete" style="border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
						</form>
					</td>
				</tr>
				</tbody>
	<?php		

	$i++;	
			   }
	}
	?>
		
	</table>
	</div>

</div>   
</body>
</html>