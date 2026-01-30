<?php
ob_start();
session_start();

include 'include/dbo.php';
include 'include/session.php';
include 'include/share_token.php';

// File Name : quote-manage.php

$debug = 0;
checksession();
$dbh    = new dbo();
$biz_id = $_SESSION['biz_id'] ?? 0;

include 'company-info.php';

// Print program: replace with your actual Quote print/share page

$quote_format_pgm = "quote-share-view.php";

// Date range
if (isset($_POST['searchbttn'])) {
  $fromDate = $_POST['searchtext1'] ?? date('Y-m-d', strtotime('-1 month'));
  $toDate   = $_POST['searchtext2'] ?? date('Y-m-d');
} else {
  $fromDate = date('Y-m-d', strtotime('-1 month'));
  $toDate   = date('Y-m-d');
}
$toDatePlus1 = date('Y-m-d', strtotime($toDate . ' +1 day'));

function e($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// 1) Fetch Quote headers
$q = " SELECT quote_id, quote_dt, quote_num, party_name, party_state, gst_txn_type, quote_status, valid_upto, created_by
       FROM quote_header
       WHERE biz_id = :biz_id
         AND quote_dt >= :fromDate
         AND quote_dt <  :toDatePlus1
       ORDER BY quote_dt DESC, quote_num DESC ";
$stmt = $dbh->prepare($q);
$stmt->execute([
  ':biz_id' => $biz_id,
  ':fromDate' => $fromDate,
  ':toDatePlus1' => $toDatePlus1
]);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($debug) $stmt->debugDumpParams();

// 2) Prepared statement for item-details per row (2nd query per row, as requested)
$item_stmt = $dbh->prepare("
  SELECT item_type, item_name, qty
  FROM quote_details
  WHERE biz_id = :biz_id
    AND parent_quote_id = :quote_id
  ORDER BY quote_detail_id ASC
");

// 3) Prepared statement for net amount per quote (derived from details)
$net_stmt = $dbh->prepare("
  SELECT COALESCE(SUM(taxable_amt + gst_amt), 0) AS net_amt
  FROM quote_details
  WHERE biz_id = :biz_id
    AND parent_quote_id = :quote_id
");
?>
<!doctype html>
<html>
<head>
  <title>Manage Quotes</title>
  <link rel="icon" type="image/png" href="images/icon.png" />
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.12.9/umd/popper.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>
  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" rel="stylesheet">

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
      #no-more-tables table, #no-more-tables thead, #no-more-tables tbody,
      #no-more-tables th, #no-more-tables td, #no-more-tables tr { display:block; }
      #no-more-tables thead tr { position:absolute; top:-9999px; left:-9999px; }
      #no-more-tables tr { border: 1px solid #ccc; }
      #no-more-tables td {
        border:none; border-bottom:1px solid #eee; position:relative;
        padding-left:50%; white-space:normal; text-align:left;
      }
      #no-more-tables td:before {
        position:absolute; top:6px; left:2px; right:2px; width:55%;
        padding-right:10px; white-space:nowrap; text-align:left; font-weight:bold;
        content: attr(data-title);
      }
      .num { text-align:left; padding-right:0; }
    }
  </style>
</head>

<body>
<div class="container col-md-12">
  <div><?php include 'header.inc.php'; ?></div>

  <div style="margin-top:50px;">
    <h2 class="text-primary text-center">Manage Quotes</h2>
  </div>

  <div class="row">
    <form name="dateRangeForm" method="post">
      <div class="col-sm-1">
        <a href="pos-index" style="border-radius:0">❮ Back</a>
      </div>

      <div class="col-sm-4"></div>

      <div class="col-sm-2">
        <strong> From: </strong>
        <input name="searchtext1" type="date" value="<?php echo e($fromDate); ?>">
      </div>

      <div class="col-sm-2">
        <strong> To: </strong>
        <input name="searchtext2" type="date" value="<?php echo e($toDate); ?>">
      </div>

      <div class="col-sm-3">
        <button type="submit" name="searchbttn" class="btn btn-default" value="1">Go</button>
      </div>
    </form>
  </div>

  <div id="no-more-tables">
    <table class="table table-striped table-bordered table-condensed"
           style="text-align:center; margin-bottom:80px;">
      <thead>
        <tr>
          <th class="col-srl">#</th>
          <th>Date</th>
          <th>Quote Num</th>
          <th  class="col-party">Party</th>
          <th>State</th>
<!--          <th>Txn</th>  -->
          <th class="item-details">Item Details (Item | Qty)</th>
          <th class="num">Net Amt</th>
          <th>Status</th>
          <th>Valid Upto</th>
          <th>Created By</th>
          <th>Update</th>
          <th>Print</th>
        </tr>
      </thead>

      <?php
      $i = 1;
      foreach ($quotes as $row):
        $quote_id = (int)$row['quote_id'];
        $encoded_quote_id = base64_encode((string)$quote_id); // obfuscation only

        // token for sharing/print (same pattern as DC)
        $token = make_print_token($biz_id, (int)$quote_id, 90);

        // net amount from details
        $net_stmt->execute([':biz_id' => $biz_id, ':quote_id' => $quote_id]);
        $net_amt = (float)($net_stmt->fetchColumn() ?? 0);

        $txn_type = (string)($row['gst_txn_type'] ?? '');
        $status   = (string)($row['quote_status'] ?? '');
        $valid_upto = $row['valid_upto'] ?? '';
      ?>
      <tbody>
        <tr>
          <td class="col-srl" data-title="#"><?php echo $i++; ?></td>
          <td data-title="Date"><?php echo e(date('d-m-Y', strtotime($row['quote_dt']))); ?></td>
          <td data-title="Quote Num"><?php echo e($row['quote_num']); ?></td>
          <td class="col-party" data-title="Party"><?php echo e($row['party_name']); ?></td>
          <td data-title="State"><?php echo e($row['party_state']); ?></td>
<!--          <td data-title="Txn"><?php echo e($txn_type); ?></td>  -->

          <td class="item-details" data-title="Item Details">
            <?php
              // Second query per row (as requested)
              $item_stmt->execute([':biz_id' => $biz_id, ':quote_id' => $quote_id]);
              $lines = $item_stmt->fetchAll(PDO::FETCH_ASSOC);

              if (!$lines) {
                echo '-';
              } else {
                foreach ($lines as $ln) {
				  if ($ln['item_type'] == "ROUND_OFF") continue;	
                  $name = $ln['item_name'] ?? '';
                  $qty  = $ln['qty'] ?? '';
                  echo e($name) . " | " . e($qty) . "<br>";
                }
              }
            ?>
          </td>

          <td class="num" data-title="Net Amt"><?php echo e(number_format($net_amt, 2)); ?></td>
          <td data-title="Status"><?php echo e($status); ?></td>
          <td data-title="Valid Upto"><?php echo $valid_upto ? e($valid_upto) : '-'; ?></td>
          <td data-title="Created By"><?php echo e($row['created_by']); ?></td>

          <td class="col-act" data-title="Update">
            <form action="quote-update.php" method="POST">
              <input type="hidden" name="src_loc" value="quote-manage">
              <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
              <input type="submit" name="quote_update" class="btn btn-danger" value="Update">
            </form>
          </td>

          <td class="col-act" data-title="Print">
            <form action="<?php echo e($quote_format_pgm); ?>" method="GET" target="pos-quote-print">
              <input type="hidden" name="view_id" value="<?php echo e($encoded_quote_id); ?>">
              <input type="hidden" name="t" value="<?php echo e($token); ?>">
              <input type="submit" class="btn btn-warning" value="Print">
            </form>
          </td>

        </tr>
      </tbody>
      <?php endforeach; ?>
    </table>
  </div>
</div>
</body>
</html>
