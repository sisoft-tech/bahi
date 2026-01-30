<?php
ob_start();
session_start();

include 'include/dbo.php';
include 'include/session.php';
include 'include/param.php';
checksession();

date_default_timezone_set('Asia/Kolkata');
$dtm = date("Y-m-d H:i:s");

$dbh = new dbo();
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$biz_id = (int)($_SESSION['biz_id'] ?? 0);
$uname  = (string)($_SESSION['biz_user_name'] ?? ($_SESSION['pos_login'] ?? ''));

function s($v): string { return trim((string)($v ?? '')); }

// Must match your existing doc types used across app
$lov_doc_type = array("SALES", "PURCHASE", "SALES RETURN", "PURCHASE RETURN", "DELIVERY CHALLAN", "QUOTE");

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

// Routing
$src_loc = s($_REQUEST['src_loc'] ?? 'config-print-doc-manage.php');
if ($src_loc === 'config-print-doc-manage') $src_loc = 'config-print-doc-manage.php';
if (!preg_match('/\.php$/i', $src_loc)) $src_loc .= '.php';

$doc_type = s($_REQUEST['doc_type'] ?? '');
if (!in_array($doc_type, $lov_doc_type, true)) {
  die("Invalid doc_type");
}
$allow = $ALLOW_BY_DOC[$doc_type] ?? [];

$err = '';
$ok  = '';

// Defaults (must satisfy NOT NULL columns)
$show_tnc = 'N';
$tnc = '';
$show_bank_ac = 'N';
$bank_ac_id = null;     // int|null
$bank_ac_text = null;   // string|null
$show_payments = 'N';
$show_sign = 'N';
$sign_file_path = null;
$use_price = 'M';
$show_po_details = 'N';
$receiver_sign = 'N';
$show_despatch_det = 'N';
$sale_invoice_format = 1;
$exists = false;

// Load
try {
  $st = $dbh->prepare("SELECT * FROM config_print_doc WHERE biz_id=? AND doc_type=? LIMIT 1");
  $st->execute([$biz_id, $doc_type]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    $exists = true;
    $show_tnc = $row['show_tnc'] ?? 'N';
    $tnc = (string)($row['tnc'] ?? '');
    $show_bank_ac = $row['show_bank_ac'] ?? 'N';
	$bank_ac_id = (isset($row['bank_ac_id']) && (int)$row['bank_ac_id'] > 0) ? (int)$row['bank_ac_id'] : null;
	$bank_ac_text = isset($row['bank_ac']) && trim((string)$row['bank_ac']) !== '' ? (string)$row['bank_ac'] : null;
    $show_payments = $row['show_payments'] ?? 'N';
    $show_sign = $row['show_sign'] ?? 'N';
    $sign_file_path = $row['sign_file_path'] ?? null;
    $use_price = $row['use_price'] ?? 'M';
    $show_po_details = $row['show_po_details'] ?? 'N';
    $receiver_sign = $row['receiver_sign'] ?? 'N';
    $show_despatch_det = $row['show_despatch_det'] ?? 'N';
    $sale_invoice_format = (int)($row['sale_invoice_format'] ?? 1);
  }
  
	$bankRows = [];
	$bs = $dbh->prepare("SELECT account_id, bank_name, ac_number, bank_ifsc_cd, branch_add
                     FROM account_bank_details
                     WHERE biz_id=?
                     ORDER BY bank_name, ac_number");
	$bs->execute([$biz_id]);
	$bankRows = $bs->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $err = $e->getMessage();
}

// Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
  try {
    // Read only allowed fields from POST, force others to safe defaults
    $show_tnc_in = isset($_POST['show_tnc']) ? 'Y' : 'N';
    $tnc_in = s($_POST['tnc'] ?? '');

/************** Bank Information ******************/
	$show_bank_in = isset($_POST['show_bank_ac']) ? 'Y' : 'N';

	$bank_ac_id_in = (int)($_POST['bank_ac_id'] ?? 0);
	$bank_ac_text_in = trim((string)($_POST['bank_ac'] ?? ''));

	if ($bank_ac_id_in <= 0) $bank_ac_id_in = null;
	if ($bank_ac_text_in === '') $bank_ac_text_in = null;

	$show_bank_ac = isAllowed($allow,'show_bank_ac') ? $show_bank_in : 'N';

	if ($show_bank_ac !== 'Y') {
	  $bank_ac_id = null;
	  $bank_ac_text = null;
	} else {
	  // Priority: ID over text
	  if ($bank_ac_id_in !== null) {
		$bank_ac_id = $bank_ac_id_in;
		$bank_ac_text = null; // recommended to avoid ambiguity
	  } else {
		$bank_ac_id = null;
		$bank_ac_text = $bank_ac_text_in;
	  }
	}

/************** Bank Information ******************/


    $show_pay_in = isset($_POST['show_payments']) ? 'Y' : 'N';

    $show_sign_in = isset($_POST['show_sign']) ? 'Y' : 'N';
    $sign_path_in = s($_POST['sign_file_path'] ?? '');

    $use_price_in = s($_POST['use_price'] ?? 'M');
    if ($use_price_in !== 'S') $use_price_in = 'M';

    $show_po_in = isset($_POST['show_po_details']) ? 'Y' : 'N';
    $recv_sign_in = isset($_POST['receiver_sign']) ? 'Y' : 'N';
    $despatch_in = isset($_POST['show_despatch_det']) ? 'Y' : 'N';

    $fmt_in = (int)($_POST['sale_invoice_format'] ?? 1);
    if ($fmt_in <= 0) $fmt_in = 1;

    // Apply allow rules
    $show_tnc = isAllowed($allow,'show_tnc') ? $show_tnc_in : 'N';
    $tnc = isAllowed($allow,'show_tnc') ? $tnc_in : '';

    $show_payments = isAllowed($allow,'show_payments') ? $show_pay_in : 'N';

    $show_sign = isAllowed($allow,'show_sign') ? $show_sign_in : 'N';
    $sign_file_path = (isAllowed($allow,'show_sign') && $show_sign === 'Y' && $sign_path_in !== '') ? $sign_path_in : null;

    $use_price = isAllowed($allow,'use_price') ? $use_price_in : 'M';

    $show_po_details = isAllowed($allow,'show_po_details') ? $show_po_in : 'N';
    $receiver_sign = isAllowed($allow,'receiver_sign') ? $recv_sign_in : 'N';
    $show_despatch_det = isAllowed($allow,'show_despatch_det') ? $despatch_in : 'N';

    $sale_invoice_format = isAllowed($allow,'sale_invoice_format') ? $fmt_in : 1;

    // Upsert
    $chk = $dbh->prepare("SELECT COUNT(*) FROM config_print_doc WHERE biz_id=? AND doc_type=?");
    $chk->execute([$biz_id, $doc_type]);
    $exists = ((int)$chk->fetchColumn() > 0);

    if (!$exists) {
      $ins = $dbh->prepare("
        INSERT INTO config_print_doc
        (biz_id, doc_type, show_tnc, tnc, show_bank_ac, bank_ac_id, bank_ac, show_payments, show_sign, sign_file_path,
         use_price, show_po_details, receiver_sign, show_despatch_det, sale_invoice_format, updt_by, updt_dtm)
        VALUES
        (:biz_id, :doc_type, :show_tnc, :tnc, :show_bank_ac, :bank_ac_id, :bank_ac, :show_payments, :show_sign, :sign_file_path,
         :use_price, :show_po_details, :receiver_sign, :show_despatch_det, :sale_invoice_format, :updt_by, :updt_dtm)
      ");
      $ins->execute([
        ':biz_id'=>$biz_id, ':doc_type'=>$doc_type,
        ':show_tnc'=>$show_tnc, ':tnc'=>$tnc,
        ':show_bank_ac'=>$show_bank_ac, ':bank_ac_id'=> $bank_ac_id,':bank_ac'=>$bank_ac_text,
        ':show_payments'=>$show_payments,
        ':show_sign'=>$show_sign, ':sign_file_path'=>$sign_file_path,
        ':use_price'=>$use_price,
        ':show_po_details'=>$show_po_details,
        ':receiver_sign'=>$receiver_sign,
        ':show_despatch_det'=>$show_despatch_det,
        ':sale_invoice_format'=>$sale_invoice_format,
        ':updt_by'=>$uname, ':updt_dtm'=>$dtm
      ]);
    } else {
      $upd = $dbh->prepare("
        UPDATE config_print_doc
        SET
          show_tnc=:show_tnc, tnc=:tnc,
          show_bank_ac=:show_bank_ac, bank_ac_id=:bank_ac_id, bank_ac=:bank_ac,
          show_payments=:show_payments,
          show_sign=:show_sign, sign_file_path=:sign_file_path,
          use_price=:use_price,
          show_po_details=:show_po_details,
          receiver_sign=:receiver_sign,
          show_despatch_det=:show_despatch_det,
          sale_invoice_format=:sale_invoice_format,
          updt_by=:updt_by, updt_dtm=:updt_dtm
        WHERE biz_id=:biz_id AND doc_type=:doc_type
      ");
      $upd->execute([
        ':show_tnc'=>$show_tnc, ':tnc'=>$tnc,
        ':show_bank_ac'=>$show_bank_ac, ':bank_ac_id'=> $bank_ac_id,':bank_ac'=>$bank_ac_text,
        ':show_payments'=>$show_payments,
        ':show_sign'=>$show_sign, ':sign_file_path'=>$sign_file_path,
        ':use_price'=>$use_price,
        ':show_po_details'=>$show_po_details,
        ':receiver_sign'=>$receiver_sign,
        ':show_despatch_det'=>$show_despatch_det,
        ':sale_invoice_format'=>$sale_invoice_format,
        ':updt_by'=>$uname, ':updt_dtm'=>$dtm,
        ':biz_id'=>$biz_id, ':doc_type'=>$doc_type
      ]);
    }

    echo "<script>
      alert(" . json_encode("Saved configuration for: ".$doc_type) . ");
      window.location.href = " . json_encode($src_loc) . ";
    </script>";
    exit;

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <link rel="icon" type="image/png" href="images/icon.png" />
  <title>Print Doc Config</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

  <style>
    body { background-color:#f7ece6; }
    .mt80 { margin-top: 90px; }
    .box { background:#fff; border:1px solid #ddd; padding:15px; border-radius:4px; }
  </style>
</head>

<body>
<div class="container-fluid">
  <div>
    <?php include 'header.inc.php'; ?>
  </div>

  <div class="container mt80">
    <div class="row">
      <div class="col-sm-2">
        <a class="btn btn-default" href="<?php echo htmlspecialchars($src_loc); ?>">Back</a>
      </div>
      <div class="col-sm-8">
        <h3 class="text-primary text-center">Config: <?php echo htmlspecialchars($doc_type); ?></h3>
      </div>
      <div class="col-sm-2"></div>
    </div>

    <?php if ($err !== ''): ?>
      <div class="alert alert-danger" style="margin-top:15px;"><?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <div class="box" style="margin-top:15px;">
      <form method="POST" class="form-horizontal">
        <input type="hidden" name="doc_type" value="<?php echo htmlspecialchars($doc_type); ?>">
        <input type="hidden" name="src_loc" value="<?php echo htmlspecialchars($src_loc); ?>">

        <?php if (isAllowed($allow,'show_tnc')): ?>
          <div class="form-group">
            <label class="control-label col-sm-4">Show Terms and Conditions</label>
            <div class="col-sm-8">
              <input type="checkbox" name="show_tnc" value="Y" <?php if ($show_tnc==='Y') echo "checked"; ?> style="width:22px;height:22px;">
            </div>
          </div>

          <div class="form-group">
            <label class="control-label col-sm-4">Terms and Conditions</label>
            <div class="col-sm-8">
              <textarea name="tnc" class="form-control" rows="5"><?php echo htmlspecialchars($tnc); ?></textarea>
            </div>
          </div>
        <?php endif; ?>

        <?php if (isAllowed($allow,'show_bank_ac')): ?>
          <div class="form-group">
            <label class="control-label col-sm-4">Show Bank Details</label>
            <div class="col-sm-8">
              <input type="checkbox" name="show_bank_ac" value="Y" <?php if ($show_bank_ac==='Y') echo "checked"; ?> style="width:22px;height:22px;">
            </div>
          </div>

          <div class="form-group">
            <label class="control-label col-sm-4">Bank Details (select account or enter text)</label>
            <div class="col-sm-8">
			<select name="bank_ac_id" class="form-control">
			  <option value="0">None (use Bank Text)</option>
			  <?php foreach ($bankRows as $b): 
				$id = (int)$b['account_id'];
				$disp = $b['bank_name']." | ".$b['ac_number']." | ".$b['bank_ifsc_cd'];
				$sel = ((int)($bank_ac_id ?? 0) === $id) ? 'selected' : '';
			  ?>
				<option value="<?php echo $id; ?>" <?php echo $sel; ?>>
				  <?php echo htmlspecialchars($disp); ?>
				</option>
			  <?php endforeach; ?>
			</select>

			<textarea name="bank_ac" class="form-control" rows="3"
			  placeholder="Fallback bank text. Used only if Bank Account is not selected. Example: Bank | Branch | A/c No | IFSC"><?php
			  echo htmlspecialchars((string)($bank_ac_text ?? ''));
			?></textarea>
            </div>
          </div>
        <?php endif; ?>



        <?php if (isAllowed($allow,'show_payments')): ?>
          <div class="form-group">
            <label class="control-label col-sm-4">Show Payments</label>
            <div class="col-sm-8">
              <input type="checkbox" name="show_payments" value="Y" <?php if ($show_payments==='Y') echo "checked"; ?> style="width:22px;height:22px;">
            </div>
          </div>
        <?php endif; ?>

        <?php if (isAllowed($allow,'show_sign')): ?>
          <div class="form-group">
            <label class="control-label col-sm-4">Show Signature</label>
            <div class="col-sm-8">
              <input type="checkbox" name="show_sign" value="Y" <?php if ($show_sign==='Y') echo "checked"; ?> style="width:22px;height:22px;">
            </div>
          </div>

          <div class="form-group">
            <label class="control-label col-sm-4">Signature File Path</label>
            <div class="col-sm-8">
              <input type="text" name="sign_file_path" class="form-control" value="<?php echo htmlspecialchars((string)($sign_file_path ?? '')); ?>" placeholder="Example: uploads/sign/my-sign.png">
            </div>
          </div>
        <?php endif; ?>

        <?php if (isAllowed($allow,'use_price')): ?>
          <div class="form-group">
            <label class="control-label col-sm-4">Use Price</label>
            <div class="col-sm-8">
              <label style="margin-right:15px;">
                <input type="radio" name="use_price" value="M" <?php if ($use_price==='M') echo "checked"; ?>> MRP
              </label>
              <label>
                <input type="radio" name="use_price" value="S" <?php if ($use_price==='S') echo "checked"; ?>> Sale Price
              </label>
            </div>
          </div>
        <?php endif; ?>

        <?php if (isAllowed($allow,'show_po_details')): ?>
          <div class="form-group">
            <label class="control-label col-sm-4">Show Purchase Order Details</label>
            <div class="col-sm-8">
              <input type="checkbox" name="show_po_details" value="Y" <?php if ($show_po_details==='Y') echo "checked"; ?> style="width:22px;height:22px;">
            </div>
          </div>
        <?php endif; ?>

        <?php if (isAllowed($allow,'receiver_sign')): ?>
          <div class="form-group">
            <label class="control-label col-sm-4">Show Receiver Signature</label>
            <div class="col-sm-8">
              <input type="checkbox" name="receiver_sign" value="Y" <?php if ($receiver_sign==='Y') echo "checked"; ?> style="width:22px;height:22px;">
            </div>
          </div>
        <?php endif; ?>

        <?php if (isAllowed($allow,'show_despatch_det')): ?>
          <div class="form-group">
            <label class="control-label col-sm-4">Show Despatch Details</label>
            <div class="col-sm-8">
              <input type="checkbox" name="show_despatch_det" value="Y" <?php if ($show_despatch_det==='Y') echo "checked"; ?> style="width:22px;height:22px;">
            </div>
          </div>
        <?php endif; ?>

        <?php if (isAllowed($allow,'sale_invoice_format')): ?>
          <div class="form-group">
            <label class="control-label col-sm-4">Sales Invoice Format</label>
            <div class="col-sm-8">
              <select class="form-control" name="sale_invoice_format">
                <?php
                  for ($i=1; $i<=5; $i++) {
                    $sel = ($sale_invoice_format === $i) ? "selected" : "";
                    echo "<option value=\"$i\" $sel>Format $i</option>";
                  }
                ?>
              </select>
            </div>
          </div>
        <?php endif; ?>

        <div class="form-group">
          <div class="col-sm-4"></div>
          <div class="col-sm-8">
            <button type="submit" name="submit" class="btn btn-info">Save</button>
            <a class="btn btn-default" href="<?php echo htmlspecialchars($src_loc); ?>" style="margin-left:6px;">Cancel</a>
          </div>
        </div>

      </form>
    </div>

  </div>
</div>
</body>
</html>
