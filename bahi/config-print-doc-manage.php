<?php
ob_start();
session_start();

include 'include/dbo.php';
include 'include/session.php';
include 'include/param.php';
checksession();

date_default_timezone_set('Asia/Kolkata');

$dbh = new dbo();
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$biz_id = (int)($_SESSION['biz_id'] ?? 0);
$uname  = (string)($_SESSION['biz_user_name'] ?? ($_SESSION['pos_login'] ?? ''));

function s($v): string { return trim((string)($v ?? '')); }

// Your existing list used elsewhere
$lov_doc_type = array("SALES", "PURCHASE", "SALES RETURN", "PURCHASE RETURN", "DELIVERY CHALLAN", "QUOTE");

/*
  Allowed fields per doc type:
  - QUOTE: no payments, but bank ok (you print bank inline with signature)
  - DELIVERY CHALLAN: no bank, no payments
*/
$ALLOW_BY_DOC = [
  'SALES' => ['show_tnc','show_bank_ac','show_payments','show_sign','use_price','show_po_details','receiver_sign','show_despatch_det','sale_invoice_format'],
  'PURCHASE' => ['show_tnc','show_sign','use_price'],
  'SALES RETURN' => ['show_tnc','show_bank_ac','show_payments','show_sign','use_price','sale_invoice_format'],
  'PURCHASE RETURN' => ['show_tnc','show_sign','use_price'],
  'DELIVERY CHALLAN' => ['show_sign','receiver_sign','show_despatch_det'],
  'QUOTE' => ['show_tnc','show_bank_ac','show_sign','use_price'],
];

function isAllowed(array $allow, string $k): bool {
  return in_array($k, $allow, true);
}

// Load all configs for biz
$stmt = $dbh->prepare("SELECT * FROM config_print_doc WHERE biz_id=?");
$stmt->execute([$biz_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byDoc = [];
foreach ($rows as $r) {
  $byDoc[(string)$r['doc_type']] = $r;
}
?>
<!doctype html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="images/icon.png" />
  <title>Print Doc Config Manage</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

  <style>
    body { background-color:#f7ece6; }
    .mt80 { margin-top: 90px; }
    .na { color:#777; }
    .badgeY { background:#5cb85c; }
    .badgeN { background:#d9534f; }
    .badgeNA{ background:#777; }
    .actform { display:inline-block; margin:0; }
  </style>
</head>

<body>
<div class="container-fluid">
  <div>
    <?php include 'header.inc.php'; ?>
  </div>

  <div class="container mt80">
    <h3 class="text-primary text-center">Print Document Configuration</h3>
    <br>

    <div class="panel panel-default">
      <div class="panel-heading">
        <b>Document Types</b>
      </div>
      <div class="panel-body" style="padding:0;">
        <div class="table-responsive">
          <table class="table table-bordered table-hover" style="margin:0;">
            <thead>
              <tr>
                <th style="width:20%;">Doc Type</th>
                <th style="width:10%;">TNC</th>
                <th style="width:10%;">Bank</th>
                <th style="width:10%;">Payments</th>
                <th style="width:10%;">Sign</th>
                <th style="width:10%;">Use Price</th>
                <th style="width:15%;">Last Updated</th>
                <th style="width:15%;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lov_doc_type as $docType): ?>
                <?php
                  $allow = $ALLOW_BY_DOC[$docType] ?? [];
                  $cfg = $byDoc[$docType] ?? null;

                  $show_tnc = $cfg['show_tnc'] ?? 'N';
                  $show_bank = $cfg['show_bank_ac'] ?? 'N';
                  $show_pay = $cfg['show_payments'] ?? 'N';
                  $show_sign = $cfg['show_sign'] ?? 'N';
                  $use_price = $cfg['use_price'] ?? 'M';
                  $updt_by = $cfg['updt_by'] ?? '';
                  $updt_dtm = $cfg['updt_dtm'] ?? '';

                  $fmtDtm = '';
                  if ($updt_dtm) {
                    $d = date_create($updt_dtm);
                    $fmtDtm = $d ? date_format($d, "d-m-Y H:i") : $updt_dtm;
                  }

                  $badge = function($v, $na=false){
                    if ($na) return '<span class="badge badgeNA">N/A</span>';
                    return ($v === 'Y') ? '<span class="badge badgeY">Y</span>' : '<span class="badge badgeN">N</span>';
                  };
                ?>
                <tr>
                  <td><b><?php echo htmlspecialchars($docType); ?></b></td>

                  <td>
                    <?php echo $badge($show_tnc, !isAllowed($allow,'show_tnc')); ?>
                  </td>
                  <td>
                    <?php echo $badge($show_bank, !isAllowed($allow,'show_bank_ac')); ?>
                  </td>
                  <td>
                    <?php echo $badge($show_pay, !isAllowed($allow,'show_payments')); ?>
                  </td>
                  <td>
                    <?php echo $badge($show_sign, !isAllowed($allow,'show_sign')); ?>
                  </td>

                  <td>
                    <?php
                      if (!isAllowed($allow,'use_price')) echo '<span class="badge badgeNA">N/A</span>';
                      else echo '<span class="badge" style="background:#337ab7;">' . htmlspecialchars($use_price) . '</span>';
                    ?>
                  </td>

                  <td>
                    <?php
                      if (!$cfg) {
						  echo '<span class="na">Not set</span>';
					  }
                      else {
						  echo htmlspecialchars($fmtDtm) . "<br><span class='na'>" . htmlspecialchars($updt_by) . "</span>";
					  }
                    ?>
                  </td>

                  <td>
                    <form class="actform" action="config-print-doc-setup.php" method="POST">
                      <input type="hidden" name="doc_type" value="<?php echo htmlspecialchars($docType); ?>">
                      <input type="hidden" name="src_loc" value="config-print-doc-manage.php">
                      <button type="submit" class="btn btn-info btn-sm"><?php echo $cfg ? 'Edit' : 'Setup'; ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>

          </table>
        </div>
      </div>
    </div>

  </div>
</div>
</body>
</html>
