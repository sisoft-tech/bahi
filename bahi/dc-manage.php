<?php
ob_start();
session_start();

include 'include/dbo.php';
include 'include/session.php';
include 'include/share_token.php';

$debug = 0;

$dbh   = new dbo();
$biz_id = $_SESSION['biz_id'] ?? 0;

include 'company-info.php';

// Print program: replace with your actual DC print page
$dc_format_pgm = "dc-share-view.php";  

// Date range
if (isset($_POST['searchbttn'])) {
  $fromDate = $_POST['searchtext1'] ?? date('Y-m-d', strtotime('-1 month'));
  $toDate   = $_POST['searchtext2'] ?? date('Y-m-d');
} else {
  $fromDate = date('Y-m-d', strtotime('-1 month'));
  $toDate   = date('Y-m-d');
}
$toDatePlus1 = date('Y-m-d', strtotime($toDate . ' +1 day'));

// Mode: search/export
$mode = $_POST['mode'] ?? 'search';
if ($mode === 'export_dc_party') {
  header("Location: export-dc-party.php?from={$fromDate}&to={$toDate}");
  exit;
}
if ($mode === 'export_dc_item') {
  header("Location: export-dc-item.php?from={$fromDate}&to={$toDate}");
  exit;
}

function e($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// 1) Fetch DC headers
$q = " SELECT dc_id, dc_dt, dc_num, bil_party_name, bil_state, net_amt, ewb_num, created_by
  FROM table_dc_header
  WHERE biz_id = :biz_id
    AND txn_type = 'DC'
    AND dc_dt >= :fromDate
    AND dc_dt <  :toDatePlus1
  ORDER BY dc_dt DESC, dc_num DESC ";
$stmt = $dbh->prepare($q);
$stmt->execute([
  ':biz_id' => $biz_id,
  ':fromDate' => $fromDate,
  ':toDatePlus1' => $toDatePlus1
]);
$dcs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2) Prepared statement for item-details per row (2nd query per row, as requested)
$item_stmt = $dbh->prepare("
  SELECT item_srl_no, item_name, qty
  FROM table_dc_details
  WHERE parent_dc_id = :dc_id
  ORDER BY item_srl_no ASC");

?>
<!doctype html>
<html>
<head>
  <title>Manage Delivery Challans</title>
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
    .item-details { text-align:left; white-space:normal; }

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
    }
  </style>
</head>

<body>
<div class="container col-md-12">
  <div><?php include 'header.inc.php'; ?></div>

  <div style="margin-top:50px;">
    <h2 class="text-primary text-center">Manage Delivery Challans</h2>
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
<!--
        <button type="submit" name="mode" value="export_dc_party"
                class="btn btn-info"
                onclick="return confirm('Export DC party data?');">
          Export DC Party
        </button>

        <button type="submit" name="mode" value="export_dc_item"
                class="btn btn-info"
                onclick="return confirm('Export DC item data?');">
          Export DC Item
        </button>
-->		
      </div>
    </form>
  </div>

  <div id="no-more-tables">
    <table class="table table-striped table-bordered table-condensed"
           style="text-align:center; margin-bottom:80px;">
      <thead>
        <tr>
          <th>#</th>
          <th>Date</th>
          <th>DC Num</th>
          <th>Party</th>
          <th>State</th>
          <th>Item Details (Item | Qty)</th>
          <th>Net Amt</th>
          <th>EWB</th>
          <th>Created By</th>
 <!--         <th>View</th>  -->
          <th>Update</th>
          <th>Print</th>
        </tr>
      </thead>

      <?php
      $i = 1;
      foreach ($dcs as $row):
        $dc_id = (int)$row['dc_id'];
        $encoded_dc_id = base64_encode((string)$dc_id); // obfuscation only
        $ewb = trim((string)($row['ewb_num'] ?? ''));
		$token = make_print_token($biz_id, (int)$dc_id, 90);
      ?>
      <tbody>
        <tr>
          <td data-title="#"><?php echo $i++; ?></td>
          <td data-title="Date"><?php echo e($row['dc_dt']); ?></td>
          <td data-title="DC Num"><?php echo e($row['dc_num']); ?></td>
          <td data-title="Party"><?php echo e($row['bil_party_name']); ?></td>
          <td data-title="State"><?php echo e($row['bil_state']); ?></td>

          <td class="item-details" data-title="Item Details">
            <?php
              // Second query per row (as requested)
              $item_stmt->execute([':dc_id' => $dc_id]);
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

          <td data-title="Net Amt"><?php echo e($row['net_amt']); ?></td>
          <td data-title="EWB"><?php echo ($ewb !== '' && $ewb !== '0') ? e($ewb) : '-'; ?></td>
          <td data-title="Created By"><?php echo e($row['created_by']); ?></td>
<!--
          <td data-title="View">
            <form action="dc-view.php" method="POST">
              <input type="hidden" name="src_loc" value="dc-manage">
              <input type="hidden" name="view_id" value="<?php echo $dc_id; ?>">
              <input type="submit" class="btn btn-info" value="View">
            </form>
          </td>
-->

          <td data-title="Update">
            <form action="dc-update.php" method="POST">
              <input type="hidden" name="src_loc" value="dc-manage">
              <input type="hidden" name="dc_id" value="<?php echo $dc_id; ?>">
              <input type="submit" name="dc_update" class="btn btn-danger" value="Update">
            </form>
          </td>

          <td data-title="Print">
            <form action="<?php echo e($dc_format_pgm); ?>" method="GET" target="pos-dc-print">
				<input type="hidden" name="view_id" value="<?php echo e($encoded_dc_id); ?>">
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
