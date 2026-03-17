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
	<title>Manage Product Item Image</title>
	<link rel="icon" type="image/png" href="images/icon.png" />
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>
	<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" type="text/css" rel="stylesheet">
	<meta http-equiv="Content-Type" content="text/html;"/>

<script>
function editItemPrice(i) {
	alert("Edit Item Price Row :"+ i) ;
	document.getElementById("pur_price"+i).readOnly=false;
	document.getElementById("mrp"+i).readOnly=false;
	document.getElementById("sale_price"+i).readOnly=false;
	document.getElementById("save"+i).style.display="block";
	document.getElementById("update"+i).style.display="none";
}


function saveItemPrice(i) {
	alert("Save Item Price Row :"+ i) ;
	var bizID = document.getElementById('biz_id').value;	
	var agentID = document.getElementById('agent_id').value;	


	var item_id = document.getElementById("itemID"+i).value;
	var pur_price = document.getElementById("pur_price"+i).value;
	var mrp = document.getElementById("mrp"+i).value;
	var sale_price = document.getElementById("sale_price"+i).value;

	var old_pur_price = document.getElementById("old_pur_price"+i).value;
	var old_mrp = document.getElementById("old_mrp"+i).value;
	var old_sale_price = document.getElementById("old_sale_price"+i).value;

	var changed = 0 ;
	if (pur_price != old_pur_price) changed = 1;
	if (mrp != old_mrp ) changed = 1 ;
	if (sale_price != old_sale_price) changed = 1 ;
	if (changed == 0) {
		alert("No Changes To Save") ;
		document.getElementById("save"+i).style.display="none";
		document.getElementById("update"+i).style.display="block";
		return ;
	}

	$.ajax({
			type: "POST",
			url: "product-item-price-update-ajax.php",
				data: {p_biz_id: bizID, p_item_id:item_id, p_pur_price: pur_price, p_mrp: mrp, p_sale_price: sale_price, p_agent_id : agentID },  
				success: function(response){ 
					console.log(response) ;					
			}
	});	

	document.getElementById("save"+i).style.display="none";
	document.getElementById("update"+i).style.display="block";
}



function customerSaving(x)
{
	var mrp = document.getElementById('mrp'+x).value ;
	var sale_price = document.getElementById('sale_price'+x).value ;
	var saving = Math.round(((mrp-sale_price)/mrp)*100) ;
	document.getElementById('saving'+x).value = saving	;
//	alert("Product Item Added Successfully....");
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
	<h2 class="text-primary text-center">Manage Product Item Price</h2>
	<form action = "product-item-add.php" method="post" style="float:right;">
	<input id="biz_id" name="biz_id" type="hidden" readonly class="form-control input-md"  value="<?php echo $biz_id;?>" >
	<input id="agent_id" name="agent_id" type="hidden" readonly class="form-control input-md"  value="<?php echo $uname;?>" >

	<input type="submit" class="btn btn-success" role="button" value="Add Product Item" style="border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
	</form>
</div> 
	<div id="no-more-tables">
	<table class="table table-bordered table-stripped table-bordered  table-condensed" style="text-align:center; margin-bottom:40px; margin-top:40px;">
	<thead>
		<th style='color:#b30059; text-align:center;'>#</th>
<!--		<th style='color:#b30059; text-align:center;'>Product Group</th>  -->
		<th style='color:#b30059; text-align:center;'>Item ID</th>		
		<th style='color:#b30059; text-align:center;'>Item Name</th>	
		<th style='color:#b30059; text-align:center;'>Item Type</th>
		<th style='color:#b30059; text-align:center;'>Purchase Price</th>
		<th style='color:#b30059; text-align:center;'>Max Retail Price</th>
		<th style='color:#b30059; text-align:center;'>Sale Price</th>
		<th style='color:#b30059; text-align:center;'>Saving</th>
        <th style='color:#b30059; text-align:center;'>Update Price</th>
	</thead>

	<?php
			  while($row = mysqli_fetch_array($result))
			   {
				   $mrp = $row['item_mrp'] ;
				   $sale_price = $row['item_sale_price'] ;
				   if ($mrp != 0 ){
					$saving = (( $mrp - $sale_price ) / $mrp ) * 100 ;
				   }
				   else
				   {
					$saving = 0 ;					   
				   }
	 ?>	
				<tbody>
				<tr>
					<td data-title="#"><?php echo $i;?></td>
<!--					<td data-title="Item Group"><?php echo $row['item_grp_id'] ;?></td>  -->
					<td data-title="Item ID"><?php echo $row['item_id'] ;?></td>										
					<td data-title="Item Name"><?php echo $row['item_name'] ;?></td>
					<td data-title="Item Type"><?php echo $row['item_type'] ;?></td>
					<td data-title="Purchase Price">
						<input type="number" id="pur_price<?php echo $i; ?>" readonly value="<?php echo $row['item_pur_price'] ?>" style="text-align:right;width:160px;">
						<input type="hidden" id="old_pur_price<?php echo $i; ?>" value="<?php echo $row['item_pur_price'] ?>">
					</td>
					<td data-title="Max Retail Price">
						<input type="number" id="mrp<?php echo $i; ?>" readonly value="<?php echo $mrp ; ?>" style="text-align:right;width:160px;"  onchange="customerSaving(<?php echo $i; ?>)">
						<input type="hidden" id="old_mrp<?php echo $i; ?>" value="<?php echo $mrp ; ?>" >
					</td>
					<td data-title="Sale Price">
						<input type="number" id="sale_price<?php echo $i; ?>" readonly value="<?php echo $sale_price ?>" style="text-align:right;width:160px;" onchange="customerSaving(<?php echo $i; ?>)">
						<input type="hidden" id="old_sale_price<?php echo $i; ?>" value="<?php echo $sale_price ?>" >

					</td>
					<td data-title="Saving">
						<input type="number" id="saving<?php echo $i; ?>" readonly value="<?php echo $saving;  ?>" style="text-align:right;width:80px;" >
					</td>

						
                    <td data-title="Upload Item">
						<input type = "hidden" id="itemID<?php echo $i; ?>" name ="update_id" value ="<?php echo $row['item_id']; ?>"/>
						<input type="button" id="update<?php echo $i; ?>" class="btn btn-primary" value="Update" onClick="editItemPrice(<?php echo $i; ?>)" />
						<input type="button" id="save<?php echo $i; ?>" class="btn btn-primary" value="Save" style="display:none;"
							onClick="saveItemPrice(<?php echo $i; ?>)"/>
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