<?php
  ob_start();
  session_start();
  include 'include/dbo.php';
  include 'include/session.php';

  $enable_ewb = 'N';
  $debug = 0;

  $dbh= new dbo() ;
  $biz_id = $_SESSION['biz_id'];


  function e($s) {
	  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
	}

  include 'company-info.php';
  
  $doc_type = "SALES" ;
  
  include 'config-print-doc-info.php';

  $invoice_format_pgm = print_doc_pgm($doc_type, 1 );

  if (isset($_POST['searchbttn'])) {
    $fromDate = $_POST['searchtext1'];
    $toDate = $_POST['searchtext2'];
  } else {
    $fromDate = date('Y-m-d', strtotime("-1 month"));
    $toDate = date('Y-m-d', strtotime("1 day"));
  }

  $i = 1;
  $base_qry = "SELECT * FROM table_invoice_header WHERE biz_id = :biz_id AND txn_type = 'SALES' AND invoice_dt BETWEEN :fromDate AND :toDate ORDER BY invoice_dt DESC, invoice_num DESC";
  $stmt = $dbh->prepare($base_qry);
  $stmt->execute([':biz_id' => $biz_id, ':fromDate' => $fromDate, ':toDate' => $toDate]);
  $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  $paid_till_stmt = $dbh->prepare("SELECT COALESCE(SUM(alloc_amount),0)  FROM money_txn_alloc
       WHERE biz_id=? AND doc_type='SALES' AND doc_id=?") ;

	$item_stmt = $dbh->prepare("
	  SELECT item_name, qty
	  FROM table_invoice_details
	  WHERE parent_invoice_id = :invoice_id   
	  ORDER BY invoice_details_id ASC
	");
	
	// And biz_id = :biz_id  Not required right now - as biz_id insert issue in detail record

?>

<html>
<head>
<title>Manage Bills</title>
<link rel="icon" type="image/png" href="images/icon.png" />
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>
<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" type="text/css" rel="stylesheet">
<meta http-equiv="Content-Type" content="text/html;"/>
<script>
function gstr1_export(){
  if (confirm("Are you sure you want to export sales party data ?")) {
    document.dateRangeForm.action = "export-sales-party.php";
  }
}

function gstr1_export_item(){
  if (confirm("Are you sure you want to export sales item data ?")) {
    document.dateRangeForm.action = "export-sales-item.php";
  }
}

function confirmDelete(delete_id) {
  return confirm("Are you sure you want to delete the data ?");
}
</script>
<style>
    table { word-wrap: break-word; table-layout:fixed; }
    tbody:nth-of-type(odd) { background: #ffffff; }
    th { background: #000; color: #ffffff; font-weight: bold; }
	table.table th, table.table td
	{
		text-align: left !important;
		padding-left: 4px !important;
		padding-right: 4px !important;
		vertical-align: top;
	}
	
    .item-details {
			width: 260px;
			max-width: 260px;
			text-align:left; 
			white-space:normal; 
			}
			
	/* Numbers: header + cells right aligned */
	  table.table th.num,
	  table.table td.num{
		text-align: right !important;
		padding-right:2px;
	  }

  /* widen Party Name column */
  th.col-party, td.col-party {
    width: 260px;           /* tweak: 220–320px as needed */
    max-width: 260px;
    white-space: normal;   /* allow wrapping for long names */
	text-align: left !important;
	padding-left: 4px !important;
  }

  /* keep serial tight (from earlier) */
  th.col-srl, td.col-srl {
    width: 34px;
    max-width: 34px;
    padding-left: 4px !important;
    padding-right: 4px !important;
    text-align: center;
    white-space: nowrap;
  }

  /* optional: action buttons */
  th.col-act, td.col-act { width: 80px; max-width: 80px; white-space: nowrap; }


@media only screen and (max-width: 800px) {
  #no-more-tables table, #no-more-tables thead, #no-more-tables tbody, #no-more-tables th, #no-more-tables td, #no-more-tables tr {
    display: block;
  }
  #no-more-tables thead tr {
    position: absolute;
    top: -9999px;
    left: -9999px;
  }
  #no-more-tables tr { border: 1px solid #ccc; }
  #no-more-tables td {
    border: none;
    border-bottom: 1px solid #eee;
    position: relative;
    padding-left: 50%;
    white-space: normal;
    text-align: left;
  }
  #no-more-tables td:before {
    position: absolute;
    top: 6px;
    left: 2px;
    right: 2px;
    width: 55%;
    padding-right: 10px;
    white-space: nowrap;
    text-align: left;
    font-weight: bold;
    content: attr(data-title);
  }
}
</style>
</head>
<body>
<div class="container col-md-12">
  <div><?php include 'header.inc.php'; ?></div>
  <div style="margin-top:50px;"><h2 class="text-primary text-center">Manage Sales</h2></div>
  <div class="row">
    <form name="dateRangeForm" method="post">
      <div class="col-sm-1"><a href='pos-index' style='border-radius:0'>❮ Back</a></div>
      <div class="col-sm-4"></div>
      <div class="col-sm-2">
        <strong> From: </strong>
        <input name="searchtext1" type="date" value="<?php echo $fromDate; ?>">
      </div>
      <div class="col-sm-2">
        <strong> To: </strong>
        <input name="searchtext2" type="date" value="<?php echo $toDate; ?>">
      </div>
      <div class="col-sm-3">
        <input type="submit" name="searchbttn" value="Go" />
        <input type="submit" name="exportbttn" value="Export Sales Party" onClick="gstr1_export();return true;" />
        <input type="submit" name="exportbttn2" value="Export Sales Item" onClick="gstr1_export_item();return true;" />
      </div>
    </form>
  </div>

  <div id="no-more-tables">
  <table class="table table-stripped table-bordered table-condensed" style="text-align:center; margin-bottom:80px;">
    <thead>
      <tr>
        <th class="col-srl">#</th>
		<th>Date</th>
		<th>Invoice Num</th>
		<th class="col-party">Customer Name</th>
        <th class="item-details">Item Details (Item | Qty)</th>
		
		<th>Total Amount</th>
        <th>Total Tax</th>
		<th>Net Amount</th>
		<th>Paid Amount/<br>Receipt</th>
        <?php if ($enable_ewb == 'Y') echo "<th>eWay Bill</th>"; ?>
        <th>Created By</th>
		<th>View</th>
		<th>Update/Print</th>
      </tr>
    </thead>
    <?php foreach ($invoices as $row): 
	        $invoice_id = (int)$row['invoice_id'];

	
	?>
    <tbody><tr>
      <td class="col-srl"><?php echo $i++; ?></td>
      <td><?php echo e(date('d-m-Y', strtotime($row['invoice_dt']))); ?></td>
      <td><?php echo $row['invoice_num']; ?></td>
      <td class="col-party"><?php echo $row['cust_name']; ?></td>
	  
	  <td class="item-details" data-title="Item Details">
            <?php
              // Second query per row (as requested)
 //             $item_stmt->execute([':biz_id' => $biz_id, ':invoice_id' => $invoice_id]);
             $item_stmt->execute([':invoice_id' => $invoice_id]);

              $lines = $item_stmt->fetchAll(PDO::FETCH_ASSOC);

              if (!$lines) {
                echo '-';
              } else {
                foreach ($lines as $ln) {
                  $name = $ln['item_name'] ?? '';
                  $qty  = $ln['qty'] ?? '';
                  echo e($name) . " | " . e($qty) . "<br>";
                }
              }
            ?>
        </td>

	  
      <td><?php echo $row['total_amt']; ?></td>
      <td><?php echo $row['total_tax']; ?></td>
      <td><?php echo $row['net_amt']; ?></td>
      <td>
        <?php
          $paid_till_stmt->execute([$biz_id, $invoice_id]) ;
		  $total_rct_amt =$paid_till_stmt->fetchColumn() ;
		  echo $total_rct_amt;
          if ($total_rct_amt != $row['net_amt']):
        ?>
		<!--
        <form action="mtxn-add.php" method="POST">
          <input type="hidden" name="inv_num" value="<?php echo $row['invoice_num']; ?>" />
          <input type="hidden" name="txn_type" value="<?php echo $row['txn_type']; ?>" />
          <input type="hidden" name="src_loc" value="bill-manage" />
          <input type="submit" name="AddTxn" class="btn btn-warning" value="Receipt" />
        </form>
		-->
		<form action="mtxnr-add.php" method="POST">
			<input type="hidden" name="invoice_id" value="<?= (int)$row['invoice_id'] ?>">
			<input type="hidden" name="src_loc" value="bill-manage">
			<button class="btn btn-warning">Receipt</button>
		</form>
		
		
		
		
		
        <?php endif; ?>
      </td>
      <?php if ($enable_ewb == 'Y'):
	    /* This is Tax Invoice, so docType will be "INV" */
		$docType = "INV" ;
        echo "<td>";
        if ($row['ewb_num'] == 0): ?>
          <form action="ewb-add.php" method="POST">
            <input type="hidden" name="biz_id" value="<?php echo $biz_id; ?>" />
            <input type="hidden" name="doc_num" value="<?php echo $row['invoice_num']; ?>" />
            <input type="hidden" name="doc_type" value="<?php echo $docType; ?>" />
			<input type="hidden" name="txn_type" value="<?php echo $row['txn_type']; ?>" />			
            <input type="hidden" name="src_loc" value="bill-manage" />
            <input type="submit" class="btn btn-warning" name="AddEWB" value="+ EWB" />
          </form>
        <?php else:
          echo $row['ewb_num'] ;
//		  echo $row['ewb_num'] . "<br><a href='https://{$row['ewb_url']}' target='_blank'>Download EWB</a><br>Manage EWB";

        endif;
        echo "</td>";
      endif; ?>
      <td><?php echo $row['invoice_created_by']; ?></td>
      <td>
        <form action="bill-view.php" method="POST">
          <input type="hidden" name="src_loc" value="bill-manage" />
          <input type="hidden" name="view_id" value="<?php echo $row['invoice_id']; ?>" />
          <input type="submit" class="btn btn-info" value="View" />
        </form>
      </td>
      <td>
<!--        
        <form action="sale-update.php" method="POST">
          <input type="hidden" name="update_id" value="<?php echo $row['invoice_id']; ?>" />
          <input type="hidden" name="src_loc" value="bill-manage" />
          <input type="submit" class="btn btn-danger" value="Update" />
        </form>
    -->        
        <form action="saleBS-update.php" method="POST">
          <input type="hidden" name="update_id" value="<?php echo $row['invoice_id']; ?>" />
          <input type="hidden" name="src_loc" value="bill-manage" />
          <input type="submit" class="btn btn-danger" value="Update" />
        </form>

        <?php $encoded_inv_ID = base64_encode($row['invoice_id']); ?>
        <form action="<?php echo $invoice_format_pgm; ?>" method="GET" target="pos-inv-print">
          <input type="hidden" name="view_id" value="<?php echo $encoded_inv_ID; ?>" />
          <input type="submit" class="btn btn-warning" value="Print" />
        </form>
      </td>
    </tr></tbody>
    <?php endforeach; ?>
  </table>
  </div>
</div>
</body>
</html>