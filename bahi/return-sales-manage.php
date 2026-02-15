<?php
  ob_start();
  session_start();
  include 'include/dbi.php';
  include 'include/session.php';
  include 'include/PDOConfig.php' ;
 
  checksession();

$debug = 0 ;
$dbh = new PDOConfig() ;
$biz = new MyBiz() ;
$biz_id = $_SESSION['biz_id'] ;	
$biz_name = $biz->getBizName($dbh, $biz_id) ;

if(isset($_POST['searchbttn']))
{
	$fromDate=$_POST['searchtext1'];
	$toDate=$_POST['searchtext2'];
}
else{
	$fromDate=date('Y-m-d',strtotime("-1 month")) ;
	$toDate=date('Y-m-d',strtotime("1 day"));
}


  
if(isset($_POST['delete_id']))  {
  $id= $_POST['delete_id'];
  $query="DELETE FROM table_invoice_header WHERE invoice_id ='$id'";
  mysqli_query($conn,$query);
  header("location:return-sales-manage.php");
  exit;
 }

  $i=1;
  
  $base_qry="select * FROM table_invoice_header where biz_id=$biz_id and txn_type='SALES RETURN' and invoice_dt between '$fromDate' and '$toDate' ORDER BY invoice_dt desc"; 
  if ($debug) echo $base_qry ;
  $result = mysqli_query($conn,$base_qry) ;
  if($result)
  {
?>
<html>
  <head>
  <title>Manage Sales Return</title>
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

</head>
  
  
<body>
  <div class="container col-md-12">
  <div>
    <?php 
	include 'header.inc.php';
	?>
  </div>
  <div style="margin-top:50px;">
	<h2 class="text-primary text-center">Manage Sales Return</h2>
  </div>

  <div class="row">  
    <form method="post" >
	<div class="col-sm-1"><a href='pos-index' style='border-radius:0; '>❮ Back</a>
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

  
  <div id="no-more-tables">
  <table class="table table-stripped table-bordered  table-condensed" style="text-align:center; margin-bottom:80px;">
  <thead>
    <th style='text-align:center;'>#</th>
    <th style='text-align:center;'>Date</th>
    <th style='text-align:center;'>Credit Note Num</th>
    <th style='text-align:center;'>Orig Invoice Num/Date</th>
    <th style='text-align:center;'>Total Amount</th>
	<!--
    <th style='text-align:center;'>Discount</th>
	-->
    <th style='text-align:center;'>Total Tax</th>
    <th style='text-align:center;'>Net Amount</th>
    <th style='text-align:center;'>Cashier Id</th>    
    <th style='text-align:center;'>Payment Status</th>
    <th style='text-align:center;'>View</th>
    <!-- <th style='text-align:center;'>Update</th> -->
    <th style='text-align:center;'>Delete</th>
  </thead>

  <?php
        while($row = mysqli_fetch_array($result))
         {
   ?> 
        <tbody>
        <tr>
          <td data-title="#"><?php echo $i;?></td>
          <td data-title="Date"><?php echo $row['invoice_dt'];?></td>	 
          <td data-title="Invoice Num"><?php echo $row['invoice_num'];?></td>
          <td data-title="Customer Id"><?php echo $row['ref_doc_no']."<BR>".$row['ref_doc_date'];?></td>
          <td data-title="Total Amount"><?php echo $row['total_amt'];?></td>
		  <!--
          <td data-title="Discount">
			<?php 
				echo $row['discount_mode'];
				$dicount_mode = $row['discount_mode'] ;
				if ($discount_mode == 'PCT') 
					$discount = $row['discount_pct'];
				else 
					$discount = $row['discount_amt'];
		  
		   echo ":".$discount;?></td>
		   -->
          <td data-title="Total Tax"><?php echo $row['total_tax'];?></td>
          <td data-title="Net Amount"><?php echo $row['net_amt'];?></td>
          <td data-title="Cashier Id"><?php echo $row['invoice_created_by'];?></td>
          <td data-title="Payment Status"><?php echo $row['payment_status'];?></td>
          <td data-title="View">
            <form action = "return-sales-share-view.php" method="GET" >
              <input type = "hidden" name ="view_id" value ="<?php echo base64_encode($row['invoice_id']); ?>"/>
               <input type="submit" class="btn btn-info" value="View" style="border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
            </form>
          </td>
          <!-- <td data-title="Update">
            <form action = "bill-update.php" method="GET" >
              <input type = "hidden" name ="update_id" value ="<?php //echo $row['invoice_id']; ?>"/>
               <input type="submit" class="btn btn-primary" value="Update" style="border-radius:0; box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2), 0 6px 20px 0 rgba(0,0,0,0.19);"/>
            </form>
          </td> -->
          <td data-title="Delete">
            <form action = "return-sales-manage.php" method="POST" onClick="return confirmDelete(this)">
              <input type = "hidden" name ="delete_id" value ="<?php echo $row['invoice_id']; ?>"/>
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