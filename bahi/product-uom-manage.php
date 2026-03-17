<?php
ob_start();
session_start();
include 'include/dbi.php';
include 'include/session.php';
include 'include/param.php';
//checksession();
$debug = 0 ;

$biz_id = $_SESSION['biz_id'] ;
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<link rel="icon" type="image/png" href="images/icon.png" />
<title>Product Item Add</title>

<meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
  
<script type="text/javascript">

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
	word-wrap: break-word;
	
	table-layout:fixed;
}
  tbody:nth-of-type(odd) {
 background: #ffffff ;
}
th {
 background: #000 ;
 color: #ffffff;
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
<div class ="container-fluid" >   	<!-- body -->
	<div>
    <?php 
	// include 'top-header.php'; 
	include 'header.inc.php';
	?>
	</div>

  <div style="margin-top:50px;">
  <h2 class="text-primary text-center">Product Unit of Measurement - Manage</h2><br>

<?php
	if(isset($_POST['delete_id']))
	{
	
	$id= $_POST['delete_id'];
    $query="DELETE FROM product_uom WHERE uom_cd ='$id'";
	if (mysqli_query($conn,$query)){
	  echo "Unit of Measurement deleted:". $id ;	
	}
	}

if(isset($_POST['submit']))
{

	$uom_cd=$_POST['uom_cd'];
	$uom_name=$_POST['uom_name'];
	$created_by=$_SESSION['pos_login'];
	$dtm = getLocalDtm() ;
	
	$insert_qry="insert into product_uom(biz_id, uom_cd,uom_desc,created_dtm,created_by) 
	             values( $biz_id, '$uom_cd',  '$uom_name','$dtm','$created_by') " ;
 
    if ($debug)  echo $insert_qry ;	
	$result= mysqli_query($conn,$insert_qry) ;
	if ($result==false){
		$error=mysqli_error($conn) ;
		echo "<BR>Error in Insert Add Unit of Measurement".$error ."<br>";
		die($error) ;
	}
	else
	{
		echo "<br>Unit of Meaurement:<b>". $uom_cd. "</b> added<br>" ;
	}
}
?>
</div>

<form class="form-horizontal" style="margin-left:10%;" method="POST" onSubmit="return validateForm(this)">
<div class="form-group row">
<label class="control-label col-md-2"  for="uom_cd">Unit Code<span style="color:red">*</span></label>  
  <div class="col-md-2">
	<input  name="uom_cd"  placeholder="" required=required class="form-control input-md" type="text">
  </div>
  
  <label class="control-label col-md-2"  for="uom_name">Unit  Name<span style="color:red">*</span></label>  
  <div class="col-md-3">
	<input  name="uom_name"  placeholder="" required=required class="form-control input-md" type="text">
  </div>
</div>

<div class="form-group" style="margin-left:35%;">
  <label class=" control-label" for="upload"></label>
  <div>
    <input type="submit" name="submit" class="btn btn-info" value="Submit" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
    <input type="reset"  name="Reset" class="btn" value="Reset" style="padding:10px 3%; border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
	</div>
</div>
</form>
<?php
// List all items..

	$base_qry="select * FROM product_uom where biz_id = $biz_id ORDER BY created_dtm DESC";	
	//if ($debug) echo $base_qry ;
	$result = mysqli_query($conn,$base_qry) ;
	if($result)
	{
		$i = 1 ;
?>
	<div id="no-more-tables">
	<table class="table table-stripped table-bordered  table-condensed" style="text-align:center; margin-bottom:80px;">
	<thead>
		<th style='text-align:center;'>#</th>
		<th style='text-align:center;'>Unit Code</th>
		<th style='text-align:center;'>Unit Name</th>
		<th style='text-align:center;'>Date</th>
		<th style='text-align:center;'>Created By</th>
<!--		<th style='text-align:center;'>Update Group</th>  -->
		<th style='text-align:center;'>Delete UoM</th>
	</thead>

	<?php
			  while($row = mysqli_fetch_array($result))
			   {
	 ?>	
				<tbody>
				<tr>
					<td data-title="#"><?php echo $i;?></td>
					<td data-title="Unit Code"><?php echo $row['uom_cd'];?></td>
					<td data-title="Unit Name"><?php echo $row['uom_desc'];?></td>
					<td data-title="Date"><?php echo $row['created_dtm'];?></td>
					<td data-title="Created By"><?php echo $row['created_by'];?></td>
					<td data-title="Delete UoM">
						<form action = "product-uom-manage.php" method="POST" onClick="return confirmDelete(this)">
							<input type = "hidden" name ="delete_id" value ="<?php echo $row['uom_cd']; ?>"/>
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

	<div style="position:absolute; width:100%; left:0; right:0; margin-top:151px;">
		<?php //include("footer.inc.php"); ?>
	</div>
</div>
</body>
</html>
