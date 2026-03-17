<?php
	error_reporting(1);
	ob_start();
	session_start();
	include "include/dbi.php";
	include "include/session.php";
	include "include/param.php";
//	checksession();
	
	$biz_id = $_SESSION['biz_id'] ;


	$i=1;
	
	$base_qry="select order_dtm, A.order_id,A.cust_phone,A.cust_name,   A.cust_add, COUNT(item_id), SUM(quantity*unit_price), order_status from order_header A, order_items B where A.order_id = B.order_id and A.order_status = 'D' and A.biz_id=$biz_id GROUP BY A.order_id";	
    //echo $base_qry;
	//if ($debug) echo $base_qry ;
	$result = mysqli_query($conn,$base_qry) ;

	if($result)
	{
?>
<html>
	<head>
	<title>Orders - Processed</title>
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
	
	
	<body style="background-color:#ccf2ff">
	
	<div class="container col-md-12" >  
 	<!-- body -->
	<div>
		<?php include 'header.inc.php'; ?>
	</div>
	<div style="margin-top:102px;">
	<h2 class="text-primary text-center">View Orders - Processed</h2>

</div> 
	<div id="no-more-tables">
	<table class="table table-bordered table-stripped table-bordered  table-condensed" style="text-align:center; margin-bottom:80px; margin-top:50px;">
	<thead>
		<th style='color:#b30059; text-align:center;'>#</th>
		<th style='color:#b30059; text-align:center;'>Date</th>		
		<th style='color:#b30059; text-align:center;'>Order Id</th>
		<th style='color:#b30059; text-align:center;'>Phone</th>
		<th style='color:#b30059; text-align:center;'>Customer Name</th>
		<th style='color:#b30059; text-align:center;'>Address</th>
		<th style='color:#b30059; text-align:center;'>Number of Items</th>
        <th style='color:#b30059; text-align:center;'>Total Amount</th>
		<th style='color:#b30059; text-align:center;'>Order Status</th>
		<th style='color:#b30059; text-align:center;'>Review order</th>
	</thead>

	<?php
			  while($row = mysqli_fetch_array($result, MYSQLI_NUM))
			   {
	 ?>	
				<tbody>
				<tr>
					<td data-title="#"><?php echo $i;?></td>
					<td data-title="Date"><?php echo $row[0] ;?></td>
					<td data-title="Order Id"><?php echo $row[1] ;?></td>
					<td data-title="Customer Name"><?php echo $row[2] ;?></td>
					<td data-title="Phone"><?php echo $row[3] ;?></td>
					<td data-title="Address"><?php echo $row[4] ;?></td>
					<td data-title="Number of Items"><?php echo $row[5] ;?></td>
					<td data-title="Total Amount"><?php echo $row[6] ;?></td>
					<td data-title="Order Status"><?php echo $row[7] ;?></td>
					
					
					
                    <td data-title="Upload Item">
						<form action = "order-view-details.php" method="GET" >
							<input type = "hidden" name ="update_id" value ="<?php echo $row[1]; ?>"/>
							 <input type="submit" class="btn btn-primary" value="Review" style="border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
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
<div style="position:fixed; bottom:0; width:100%; left:0; right:0;">
		<?php // include("footer.inc.php"); ?>
	</div>	
	</body>
</html>