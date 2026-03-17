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
<script>
function updateAvailability(itemID,avail){
	alert("Update Availability:"+itemID+":"+avail) ;
	$.ajax({
				type: "POST",
				url: "product-item-avail-status-ajax.php",
				data: {item_id:itemID, avail_status:avail },  
				success: function(response){ 
					window.location.reload(); 
			}
	});	

}

</script>
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
	<h2 class="text-primary text-center">Manage Product Items Availability</h2>
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
		<th style='color:#b30059; text-align:center;'>Manufacturer Part code</th>
		<th style='color:#b30059; text-align:center;'>Available Qty</th>
		<th style='color:#b30059; text-align:center;'>Availability Status</th>	
        <th style='color:#b30059; text-align:center;'>View Item</th>
        <th style='color:#b30059; text-align:center;'>View Stock Journal</th>
        <th style='color:#b30059; text-align:center;'>Adjust Stock Quantity</th>
		
	</thead>

	<?php
			  while($row = mysqli_fetch_array($result))
			   {
   					$item_id=$row['item_id'] ;	

	 ?>	
				<tbody>
				<tr>
					<td data-title="#"><?php echo $i;?></td>
					<td data-title="Product Group"><?php echo $row['item_grp_id'] ;?></td>
					<td data-title="Product Name"><?php echo $row['item_name'] ;?></td>
					<td data-title="Product Type"><?php echo $row['item_type'] ;?></td>
					<td data-title="Manufacturer Part Code"><?php echo $row['manu_code'] ;?></td>
					<td data-title="Available Qty"><?php echo $row['avail_qty'] ;	?>				</td>
					<td data-title="Availability Status"><?php
					$avail=$row['avail_status'] ;
					$method="updateAvailability('".$item_id."','".$avail."')" ;
					
					if ($avail == 'Y') {
						echo '<button type="button" class="btn btn-success" onClick='.$method.'>Y</button>' ;
					}
					else {
						echo '<button type="button" class="btn btn-danger" onClick='.$method.'>N</button>' ;
					}
					?>
					</td>

					
                    <td data-title="View Item">
						<form action = "product-item-view.php" method="POST" >
							<input type = "hidden" name ="view_id" value ="<?php echo $row['item_id']; ?>"/>
							 <input type="submit" class="btn btn-primary" value="View" style="border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
						</form>
					</td>
                    <td data-title="View Stock Journal">
						<form action = "product-item-stk-jrnl.php" method="POST" >
							<input type = "hidden" name ="view_id" value ="<?php echo $row['item_id']; ?>"/>
							<input type = "hidden" name ="item_name" value ="<?php echo $row['item_name']; ?>"/>
							 <input type="submit" class="btn btn-primary" value="View" style="border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
						</form>
					</td>
                    <td data-title="Update Stock">
						<form action = "product-item-qty-update-form.php" method="POST" >
							<input type = "hidden" name ="item_id" value ="<?php echo $row['item_id']; ?>"/>
							<input type = "hidden" name ="item_name" value ="<?php echo $row['item_name']; ?>"/>
							<input type = "hidden" name ="avail_qty" value ="<?php echo $row['avail_qty']; ?>"/>
							 <input type="submit" class="btn btn-primary" value="Adjust" style="border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
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
    
		<?php // include("footer.inc.php"); ?>

	</body>
</html>