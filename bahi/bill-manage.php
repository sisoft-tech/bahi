<?php
ob_start();
session_start();

include 'include/dbo.php';
include 'include/session.php';

checksession();

$enable_ewb = 'N';
$debug = 0;

$dbh = new dbo();
$biz_id = (int)($_SESSION['biz_id'] ?? 0);
if ($biz_id <= 0) {
    die('Invalid session (biz).');
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_amt($n) {
    return number_format((float)$n, 2, '.', '');
}

include 'company-info.php';

$doc_type = 'SALES';
include 'config-print-doc-info.php';
$invoice_format_pgm = print_doc_pgm($doc_type, 1);

if (isset($_POST['searchbttn']) || isset($_POST['exportbttn']) || isset($_POST['exportbttn2'])) {
    $fromDate = trim((string)($_POST['searchtext1'] ?? ''));
    $toDate   = trim((string)($_POST['searchtext2'] ?? ''));
} else {
    $fromDate = date('Y-m-d', strtotime('-1 month'));
    $toDate   = date('Y-m-d');
}

if ($fromDate === '') {
    $fromDate = date('Y-m-d', strtotime('-1 month'));
}
if ($toDate === '') {
    $toDate = date('Y-m-d');
}

if ($fromDate > $toDate) {
    [$fromDate, $toDate] = [$toDate, $fromDate];
}

// Sales invoices with allocated receipt amount.
$stmt = $dbh->prepare(" 
    SELECT
        ih.*,
        COALESCE(pay.paid_amt, 0) AS paid_amt
    FROM table_invoice_header ih
    LEFT JOIN (
        SELECT doc_id, COALESCE(SUM(alloc_amount), 0) AS paid_amt
        FROM money_txn_alloc
        WHERE biz_id = :paid_biz_id
          AND doc_type = 'SALES'
        GROUP BY doc_id
    ) pay ON pay.doc_id = ih.invoice_id
    WHERE ih.biz_id = :biz_id
      AND ih.txn_type = 'SALES'
      AND ih.invoice_dt >= :from_date
      AND ih.invoice_dt < DATE_ADD(:to_date, INTERVAL 1 DAY)
    ORDER BY ih.invoice_dt DESC, ih.invoice_id DESC
");

$stmt->execute([
    ':paid_biz_id' => $biz_id,
    ':biz_id'      => $biz_id,
    ':from_date'   => $fromDate,
    ':to_date'     => $toDate
]);

$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$item_stmt = $dbh->prepare(" 
    SELECT item_name, item_type, qty
    FROM table_invoice_details
    WHERE parent_invoice_id = :invoice_id
    ORDER BY invoice_details_id ASC
");

$colspan = ($enable_ewb === 'Y') ? 13 : 12;
?>
<!doctype html>
<html lang="en">
<head>
<title>Manage Sales Invoices</title>
<link rel="icon" type="image/png" href="images/icon.png">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet">
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

<script>
function gstr1_export(){
  var agree = confirm('Are you sure you want to export sales party data?');
  if (agree) {
    document.dateRangeForm.action = 'export-sales-party.php';
    return true;
  }
  return false;
}

function gstr1_export_item(){
  var agree = confirm('Are you sure you want to export sales item data?');
  if (agree) {
    document.dateRangeForm.action = 'export-sales-item.php';
    return true;
  }
  return false;
}
</script>

<style>
table {
  word-wrap: break-word;
  table-layout: fixed;
}

.sales-table thead th {
  background: #337ab7;
  color: #fff;
  font-weight: bold;
  text-align: center;
  vertical-align: middle !important;
}

.sales-table td {
  vertical-align: top !important;
}

.amount-cell {
  text-align: right !important;
  padding-right: 10px !important;
}

.customer-cell,
.item-details {
  text-align: left !important;
  padding-left: 5px !important;
  white-space: normal;
}

.action-form {
  margin: 0;
}

.action-form + .action-form {
  margin-top: 4px;
}

.filter-row {
  margin-bottom: 15px;
}

.sales-table .btn {
  border-radius: 0;
  box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

@media only screen and (max-width: 800px) {
  #no-more-tables table,
  #no-more-tables thead,
  #no-more-tables tbody,
  #no-more-tables th,
  #no-more-tables td,
  #no-more-tables tr {
    display: block;
  }

  #no-more-tables table {
    table-layout: auto;
    width: 100%;
  }

  #no-more-tables thead tr {
    position: absolute;
    top: -9999px;
    left: -9999px;
  }

  #no-more-tables tr {
    border: 1px solid #ccc;
    margin-bottom: 10px;
    background: #fff;
    border-radius: 4px;
    overflow: hidden;
  }

  #no-more-tables td {
    border: none;
    border-bottom: 1px solid #eee;
    position: relative;
    padding-left: 52% !important;
    padding-top: 8px;
    padding-bottom: 8px;
    white-space: normal;
    text-align: left !important;
    min-height: 36px;
  }

  #no-more-tables td:before {
    position: absolute;
    top: 8px;
    left: 8px;
    width: 48%;
    padding-right: 10px;
    white-space: nowrap;
    text-align: left;
    font-weight: bold;
    content: attr(data-title);
  }

  .amount-cell {
    text-align: left !important;
    padding-right: 0 !important;
  }

  .customer-cell,
  .item-details {
    padding-left: 52% !important;
  }

  .action-form {
    margin-top: 5px;
  }

  .action-form .btn {
    width: 100%;
    display: block;
  }
}
</style>
</head>

<body>
<div class="container col-md-12">
  <div><?php include 'header.inc.php'; ?></div>

  <div style="margin-top:50px;">
    <h2 class="text-primary text-center">Manage Sales Invoices</h2>
  </div>

  <form name="dateRangeForm" method="post" class="filter-row">
    <div class="row">
      <div class="col-sm-1">
        <a href="pos-index" style="border-radius:0">❮ Back</a>
      </div>
      <div class="col-sm-3"></div>
      <div class="col-sm-2">
        <strong>From:</strong>
        <input name="searchtext1" id="searchtext1" type="date" value="<?php echo h($fromDate); ?>">
      </div>
      <div class="col-sm-2">
        <strong>To:</strong>
        <input name="searchtext2" id="searchtext2" type="date" value="<?php echo h($toDate); ?>">
      </div>
      <div class="col-sm-4">
        <input type="submit" name="searchbttn" value="Go">
        <input type="submit" name="exportbttn" value="Export Sales Party" onclick="return gstr1_export();">
        <input type="submit" name="exportbttn2" value="Export Sales Item" onclick="return gstr1_export_item();">
      </div>
    </div>
  </form>

  <div id="no-more-tables">
    <table class="table table-striped table-bordered table-condensed sales-table" style="text-align:center; margin-bottom:80px;">
      <thead>
        <tr>
          <?php if ($enable_ewb === 'Y'): ?>
            <th style="width:4%;">#</th>
            <th style="width:7%;">Date</th>
            <th style="width:8%;">Invoice Num</th>
            <th style="width:15%;">Customer Name</th>
            <th style="width:16%;">Item Details</th>
            <th style="width:7%;">Total Amount</th>
            <th style="width:6%;">Total Tax</th>
            <th style="width:7%;">Net Amount</th>
            <th style="width:8%;">Paid / Receipt</th>
            <th style="width:5%;">eWay Bill</th>
            <th style="width:6%;">Created By</th>
            <th style="width:5%;">Update</th>
            <th style="width:6%;">View / Print</th>
          <?php else: ?>
            <th style="width:4%;">#</th>
            <th style="width:7%;">Date</th>
            <th style="width:9%;">Invoice Num</th>
            <th style="width:17%;">Customer Name</th>
            <th style="width:18%;">Item Details</th>
            <th style="width:7%;">Total Amount</th>
            <th style="width:7%;">Total Tax</th>
            <th style="width:8%;">Net Amount</th>
            <th style="width:9%;">Paid / Receipt</th>
            <th style="width:7%;">Created By</th>
            <th style="width:5%;">Update</th>
            <th style="width:5%;">View / Print</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($invoices)): ?>
        <tr>
          <td colspan="<?php echo (int)$colspan; ?>" class="text-center">No sales invoices found for the selected date range.</td>
        </tr>
      <?php else: ?>
        <?php $i = 1; ?>
        <?php foreach ($invoices as $row): ?>
          <?php
            $invoice_id = (int)$row['invoice_id'];
            $paid_amt   = (float)($row['paid_amt'] ?? 0);
            $net_amt    = (float)($row['net_amt'] ?? 0);
            $show_receipt_button = (round($paid_amt, 2) != round($net_amt, 2));
            $encoded_inv_ID = base64_encode((string)$invoice_id);
          ?>
          <tr>
            <td data-title="#"><?php echo $i++; ?></td>
            <td data-title="Date"><?php echo h(date('d-m-Y', strtotime((string)$row['invoice_dt']))); ?></td>
            <td data-title="Invoice Num"><?php echo h($row['invoice_num']); ?></td>
            <td data-title="Customer Name" class="customer-cell"><?php echo h($row['cust_name']); ?></td>
            <td data-title="Item Details" class="item-details">
              <?php
                $item_stmt->execute([':invoice_id' => $invoice_id]);
                $lines = $item_stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$lines) {
                    echo '-';
                } else {
                    foreach ($lines as $ln) {
                        $item_type = strtoupper((string)($ln['item_type'] ?? ''));
                        $name = $ln['item_name'] ?? '';
                        $qty  = $ln['qty'] ?? '';

                        if ($item_type === 'CHARGE' || $item_type === 'ROUND_OFF') {
                            echo h($name) . '<br>';
                        } else {
                            echo h($name) . ' | ' . h($qty) . '<br>';
                        }
                    }
                }
              ?>
            </td>
            <td data-title="Total Amount" class="amount-cell"><?php echo fmt_amt($row['total_amt']); ?></td>
            <td data-title="Total Tax" class="amount-cell"><?php echo fmt_amt($row['total_tax']); ?></td>
            <td data-title="Net Amount" class="amount-cell"><?php echo fmt_amt($net_amt); ?></td>
            <td data-title="Paid / Receipt" class="amount-cell">
              <?php echo fmt_amt($paid_amt); ?>
              <?php if ($show_receipt_button): ?>
                <form action="mtxnr-add.php" method="POST" class="action-form">
                  <input type="hidden" name="invoice_id" value="<?php echo (int)$invoice_id; ?>">
                  <input type="hidden" name="src_loc" value="bill-manage">
                  <button type="submit" class="btn btn-warning btn-xs">Receipt</button>
                </form>
              <?php else: ?>
                <br><span class="label label-success">Paid</span>
              <?php endif; ?>
            </td>

            <?php if ($enable_ewb === 'Y'): ?>
              <td data-title="eWay Bill">
                <?php
                  $docType = 'INV';
                  if ((int)($row['ewb_num'] ?? 0) === 0):
                ?>
                  <form action="ewb-add.php" method="POST" class="action-form">
                    <input type="hidden" name="biz_id" value="<?php echo (int)$biz_id; ?>">
                    <input type="hidden" name="doc_num" value="<?php echo h($row['invoice_num']); ?>">
                    <input type="hidden" name="doc_type" value="<?php echo h($docType); ?>">
                    <input type="hidden" name="txn_type" value="<?php echo h($row['txn_type']); ?>">
                    <input type="hidden" name="src_loc" value="bill-manage">
                    <input type="submit" class="btn btn-warning btn-xs" name="AddEWB" value="+ EWB">
                  </form>
                <?php else: ?>
                  <?php echo h($row['ewb_num']); ?>
                <?php endif; ?>
              </td>
            <?php endif; ?>

            <td data-title="Created By"><?php echo h($row['invoice_created_by']); ?></td>

            <td data-title="Update">
              <form action="saleBS-update.php" method="POST" class="action-form">
                <input type="hidden" name="update_id" value="<?php echo (int)$invoice_id; ?>">
                <input type="hidden" name="src_loc" value="bill-manage">
                <input type="submit" class="btn btn-danger btn-xs" value="Update">
              </form>
            </td>

            <td data-title="View / Print">
              <form action="bill-view.php" method="POST" class="action-form">
                <input type="hidden" name="src_loc" value="bill-manage">
                <input type="hidden" name="view_id" value="<?php echo (int)$invoice_id; ?>">
                <input type="submit" class="btn btn-info btn-xs" value="View">
              </form>

              <form action="<?php echo h($invoice_format_pgm); ?>" method="GET" target="pos-inv-print" class="action-form">
                <input type="hidden" name="view_id" value="<?php echo h($encoded_inv_ID); ?>">
                <input type="submit" class="btn btn-warning btn-xs" value="Print">
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
