<?php
ob_start();
session_start();

require 'include/dbo.php';        // PDO class dbo()
require 'include/session.php';
require 'include/param-pos.php';  // expects $list_india_state array etc.

checksession();

$username_head = $_SESSION['login'] ?? '';
$dbh = new dbo(); // throws on failure

// Look up the logged-in admin user by email
$admin_id = null;

if ($username_head !== '') {
    try {
        $stmtAdmin = $dbh->prepare("
            SELECT id
            FROM biz_admin_users
            WHERE admin_email = :email
            LIMIT 1
        ");
        $stmtAdmin->execute([':email' => $username_head]);
        $adminRow = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

        if ($adminRow) {
            $admin_id = (int)$adminRow['id'];
        }
    } catch (Throwable $e) {
        $admin_id = 0; // fallback handled later
        error_log("No record exist for $username_head");
    }
}

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// helpers
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function old($name, $default = '') { return e($_POST[$name] ?? $default); }

$errors = [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    // CSRF check
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
        $errors[] = 'Invalid form token. Please refresh and try again.';
    }

    // Collect & normalize
    $category   = isset($_POST['category']) ? (int)$_POST['category'] : null;
    $cname      = trim($_POST['cname'] ?? '');
    $aboutus    = trim($_POST['aboutus'] ?? '');
    $street_add = trim($_POST['caddress1'] ?? '');
    $khand_name = trim($_POST['ckhand'] ?? '');
    $city       = trim($_POST['city'] ?? '');
    $district   = trim($_POST['district'] ?? '');
    $state      = trim($_POST['state'] ?? '');
    $country    = trim($_POST['country'] ?? 'India');
    $pin        = trim($_POST['pin'] ?? '');
    $url        = trim($_POST['weburl'] ?? '');
    $email      = trim($_POST['cemail'] ?? '');
    $phone1     = preg_replace('/\D+/', '', (string)($_POST['cphone1'] ?? '')); // keep digits
    $phone2     = preg_replace('/\D+/', '', (string)($_POST['cphone2'] ?? ''));
    $currency   = strtoupper(trim($_POST['currency'] ?? 'INR'));
    $tax_status = $_POST['biz_tax_reg_status'] ?? '';   // 'U' or 'R'
    $gstin      = strtoupper(trim($_POST['biz_gstin'] ?? ''));
    $userAdded  = $username_head;

    // Server-side validation
    if (!$category)                                   $errors[] = 'Business category is required.';
    if ($cname === '')                                $errors[] = 'Business name is required.';
//    if ($aboutus === '')                              $errors[] = 'About Business is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))   $errors[] = 'Invalid email address.';
    if (!preg_match('/^\d{10}$/', $phone1))           $errors[] = 'Primary phone must be exactly 10 digits.';
    if ($phone2 !== '' && !preg_match('/^\d{10}$/', $phone2)) $errors[] = 'Alt phone must be exactly 10 digits.';
    if ($pin === '' || !preg_match('/^\d{6}$/', $pin))         $errors[] = 'PIN must be a 6-digit code.';
//    if ($city === '')                                 $errors[] = 'City is required.';
//    if ($state === '')                                $errors[] = 'State is required.';
//    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) $errors[] = 'Invalid website URL.';
    if (!in_array($tax_status, ['U','R'], true))               $errors[] = 'Invalid tax registration status.';
    if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) $errors[] = 'Currency must be a 3-letter ISO code.';
    if ($tax_status === 'R' && $gstin === '')         $errors[] = 'GSTIN is required for registered businesses.';
    if ($gstin !== '' && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/i', $gstin)) {
        $errors[] = 'Invalid GSTIN format.';
    }

    if (!$errors) {
        try {
            $dbh->beginTransaction();

            // ---------- 1) Insert establishment ----------
            $sql = "INSERT INTO biz_establishment
                (biz_name, biz_details, biz_phone1, biz_phone2, biz_email, biz_website,
                 biz_street, biz_area, biz_city, biz_district, biz_state, biz_pin, biz_country,
                 bcat_id, biz_currency, biz_tax_reg_status, biz_gstin, disp_status, user_added)
                VALUES
                (:biz_name, :biz_details, :biz_phone1, :biz_phone2, :biz_email, :biz_website,
                 :biz_street, :biz_area, :biz_city, :biz_district, :biz_state, :biz_pin, :biz_country,
                 :bcat_id, :biz_currency, :biz_tax_reg_status, :biz_gstin, :disp_status, :user_added)";

            $stmt = $dbh->prepare($sql);
            $stmt->execute([
                ':biz_name'            => $cname,
                ':biz_details'         => $aboutus,
                ':biz_phone1'          => $phone1,
                ':biz_phone2'          => $phone2,
                ':biz_email'           => $email,
                ':biz_website'         => $url,
                ':biz_street'          => $street_add,
                ':biz_area'            => $khand_name,
                ':biz_city'            => $city,
                ':biz_district'        => $district,
                ':biz_state'           => $state,
                ':biz_pin'             => $pin,
                ':biz_country'         => $country,
                ':bcat_id'             => $category,
                ':biz_currency'        => $currency,
                ':biz_tax_reg_status'  => $tax_status,
                ':biz_gstin'           => $gstin,
                ':disp_status'         => 'Y',
                ':user_added'          => $userAdded
            ]);

            $biz_id = (int)$dbh->lastInsertId();

            // ---------- 2) Give creator OWNER access (biz_estab_user_access) ----------
            $creatorEmail = $_SESSION['login'] ?? '';

            $ins = $dbh->prepare("
                INSERT INTO biz_estab_user_access
                (biz_id, user_email, access_role, status, created_by)
                VALUES
                (:biz_id, :user_email, 'owner', 'active', :created_by)
                ON DUPLICATE KEY UPDATE
                    access_role = VALUES(access_role),
                    status      = 'active'
            ");
            $ins->execute([
                ':biz_id'     => $biz_id,
                ':user_email' => $creatorEmail,
                ':created_by' => $creatorEmail,
            ]);

            // ---------- 3) Create initial app-level trial subscription (biz_subs_contract) ----------
            //
            // App-level, prod_item_name used as app code.
            // Here: trial for Billing Desktop => prod_item_name = 'billing'
            //
            // Assumes biz_subs_contract has subs_plan column:
            //   free|trial|paid

            $contractSql = "INSERT INTO biz_subs_contract
                (biz_id, cust_id, cust_email,
                 prod_item_name, prod_srl_no, prod_notes,
                 subs_start_dt, subs_end_dt, subs_plan, subs_status,
                 amt_notes, is_renewal, parent_contract_id,
                 updated_by, updated_dtm,
                 created_by, created_dtm, created_ip,
                 invoice_notes, receipt_status)
                VALUES
                (:biz_id, :cust_id, :cust_email,
                 :prod_item_name, :prod_srl_no, :prod_notes,
                 CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), :subs_plan, 'active',
                 :amt_notes, 'N', NULL,
                 :updated_by, NULL,
                 :created_by, NOW(), :created_ip,
                 :invoice_notes, :receipt_status)";

            $contractStmt = $dbh->prepare($contractSql);
            $contractStmt->execute([
                ':biz_id'         => $biz_id,
                ':cust_id'        => $admin_id ?? 0,
                ':cust_email'     => $email,                    // business contact email
                ':prod_item_name' => 'billing',                 // app code: Billing Desktop
                ':prod_srl_no'    => null,
                ':prod_notes'     => 'Initial trial subscription',
                ':subs_plan'      => 'trial',                   // free|trial|paid
                ':amt_notes'      => 'Trial: 30 days',
                ':updated_by'     => $username_head ?: 'system',
                ':created_by'     => $username_head ?: 'system',
                ':created_ip'     => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                ':invoice_notes'  => null,
                ':receipt_status' => 'unpaid'
            ]);

            // Note: biz_establishment no longer has subs_contract_id,
            // so we do NOT update it here. All subscription logic is app-level.

            $dbh->commit();

            // Regenerate CSRF token after successful POST-redirect
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            $_SESSION['biz_id'] = $biz_id; // optional but handy
            header('Location: biz-seed-data.php?biz_id='.$biz_id);
            exit;
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) { $dbh->rollBack(); }
            // Optional: log full error
            // error_log('biz-mybusiness-add error: '.$e->getMessage());
            $errors[] = 'We could not save your business at the moment. Please try again.';
        }
    }
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>Business Listing - Add your business</title>
<link rel="shortcut icon" type="image/icon" href="image/icon-main.png"/>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="description" content="Business Listing for Local Business - Add your business , free business listing" />
<meta name="keywords" content="Free Business Listing" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">

<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
<!-- SCRIPT-->
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

<style type="text/css">
.row{
  text-decoration: none;
  font-size: 16px;
  font-weight: bold;
  margin-top:10px;
}
</style>

<script type="text/javascript">
// Keep your original hook name to avoid template changes
function ckeckData() {
  var f = document.testform;
  if (!f.category.value) { alert("Please select the Business Category"); f.category.focus(); return false; }
  if (!f.cname.value.trim()) { alert("Please enter the Company Name"); f.cname.focus(); return false; }
  if (!f.cemail.value.trim()) { alert("Please enter the Email ID"); f.cemail.focus(); return false; }
  if (!f.cphone1.value.trim()) { alert("Please enter the Phone No"); f.cphone1.focus(); return false; }
  if (!f.caddress1.value.trim()) { alert("Please enter the Address"); f.caddress1.focus(); return false; }
  return true;
}
</script>
</head>
<body>

<?php include "biz-header.php"; ?>

<div class="container">
  <div class="row">
    <div class="col-sm-2" style="margin-top:30px;">
      <button type="button" class="btn" onClick="window.location.assign('biz-mybusiness-manage.php')">Back</button>
    </div>
    <div class="col-sm-10">
      <h3 style="text-align:center;">Add Business</h3>
    </div>
  </div>
  <br/>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul style="margin-bottom:0;">
        <?php foreach ($errors as $err): ?>
          <li><?= e($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php elseif ($notice): ?>
    <div class="alert alert-info"><?= e($notice) ?></div>
  <?php endif; ?>

  <form method="POST" name="testform" enctype="multipart/form-data" onsubmit="return ckeckData();">
    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>" />

    <div class="row">
      <div class="col-lg-2">
        <label>Choose Category</label>
      </div>
      <div class="col-lg-4">
        <select id="category" name="category" class="form-control" required>
          <option value="" disabled <?= old('category')===''?'selected':''; ?>>Choose Category</option>
          <?php
          try {
              $cat_stmt = $dbh->query("SELECT bcat_id, bcat_name FROM biz_category WHERE DISP_STATUS='Y' ORDER BY bcat_name DESC");
              while ($categories = $cat_stmt->fetch(PDO::FETCH_ASSOC)) {
                  $sel = ((string)$categories['bcat_id'] === ($_POST['category'] ?? '')) ? ' selected' : '';
                  echo '<option value="'.(int)$categories['bcat_id'].'"'.$sel.'>'.e($categories['bcat_name']).'</option>';
              }
          } catch (Throwable $e) {
              echo '<option disabled>Error loading categories</option>';
          }
          ?>
        </select>
      </div>

      <div class="col-lg-2">
        <label for="cname">Business Name(*)</label>
      </div>
      <div class="input-field col-lg-4">
        <input required name="cname" type="text" id="cname" class="form-control" value="<?= old('cname') ?>"/>
      </div>
    </div>

    <div class="row">
      <div class="col-lg-2">
        <label for="aboutus">About Business(*)</label>
      </div>
      <div class="col-lg-10">
        <textarea required name="aboutus" id="aboutus" class="form-control" style="width: 100%; height: 80px;"><?= old('aboutus') ?></textarea>
      </div>
    </div>

    <div class="row">
      <div class="col-sm-2">
        <label for="weburl">Business WebSite<br/>(if any)</label>
      </div>
      <div class="input-field col-sm-4">
        <input type="url" name="weburl" id="weburl" class="form-control" value="<?= old('weburl') ?>"/>
      </div>
      <div class="col-sm-2">
        <label for="cemail">Email(*)</label>
      </div>
      <div class="input-field col-sm-4">
        <input required name="cemail" type="email" id="cemail" class="form-control" value="<?= old('cemail') ?>"/>
      </div>
    </div>

    <div class="row">
      <div class="col-sm-2">
        <label for="cphone1">Phone(*)</label>
      </div>
      <div class="input-field col-sm-4">
        <input required name="cphone1" type="text" maxlength="10" id="cphone1" class="form-control" pattern="\d{10}" value="<?= old('cphone1') ?>"/>
      </div>
      <div class="col-sm-2">
        <label for="cphone2">Alt Phone</label>
      </div>
      <div class="input-field col-sm-4">
        <input name="cphone2" type="text" maxlength="10" id="cphone2" class="form-control" pattern="\d{10}" value="<?= old('cphone2') ?>"/>
      </div>
    </div>

    <div class="row">
      <div class="col-sm-2"><label for="caddress1">Street Address</label></div>
      <div class="input-field col-sm-4">
        <input name="caddress1" type="text" id="caddress1" class="form-control" value="<?= old('caddress1') ?>"/>
      </div>
      <div class="col-sm-2"><label for="ckhand">Area</label></div>
      <div class="input-field col-sm-4">
        <input name="ckhand" type="text" id="ckhand" class="form-control" value="<?= old('ckhand') ?>"/>
      </div>
    </div>

    <div class="row">
      <div class="col-sm-2"><label for="pin">Pin Code(*)</label></div>
      <div class="input-field col-sm-4">
        <input required name="pin" type="text" id="pin" class="form-control" pattern="\d{6}" maxlength="6" value="<?= old('pin') ?>"/>
      </div>
      <div class="col-sm-2"><label for="city">City(*)</label></div>
      <div class="input-field col-sm-4">
        <input required name="city" type="text" id="city" class="form-control" value="<?= old('city') ?>"/>
      </div>
    </div>

    <div class="row">
      <div class="col-sm-2"><label for="district">District</label></div>
      <div class="input-field col-sm-4">
        <input name="district" type="text" id="district" class="form-control" value="<?= old('district') ?>"/>
      </div>
      <div class="col-sm-2"><label for="state">State(*)</label></div>
      <div class="input-field col-sm-4">
        <select class="form-control" name="state" id="state" required>
          <option value="" disabled <?= old('state')===''?'selected':''; ?>>Choose State</option>
          <?php
            if (isset($list_india_state) && is_array($list_india_state)) {
                foreach ($list_india_state as $st) {
                    $sel = ($st === ($_POST['state'] ?? '')) ? ' selected' : '';
                    echo '<option value="'.e($st).'"'.$sel.'>'.e($st).'</option>';
                }
            }
          ?>
        </select>
      </div>
    </div>

    <div class="row">
      <div class="col-sm-2"><label for="country">Country</label></div>
      <div class="input-field col-sm-4">
        <input name="country" type="text" id="country" class="form-control" value="<?= old('country','India') ?>"/>
      </div>
      <div class="col-sm-2"><label for="currency">Currency</label></div>
      <div class="input-field col-sm-4">
        <input name="currency" type="text" id="currency" class="form-control" maxlength="3" value="<?= old('currency','INR') ?>"/>
      </div>
    </div>

    <div class="row">
      <div class="col-sm-2"><label for="biz_tax_reg_status">Business Tax Registration Status(*)</label></div>
      <div class="input-field col-sm-4">
        <select name="biz_tax_reg_status" id="biz_tax_reg_status" class="form-control" required>
          <option value="" <?= old('biz_tax_reg_status')===''?'selected':''; ?>>Choose Tax Registration Status</option>
          <option value="U" <?= (($_POST['biz_tax_reg_status'] ?? '')==='U')?'selected':''; ?>>Un-Registered</option>
          <option value="R" <?= (($_POST['biz_tax_reg_status'] ?? '')==='R')?'selected':''; ?>>Registered</option>
        </select>
      </div>
      <div class="col-sm-2"><label for="biz_gstin">GSTIN</label></div>
      <div class="input-field col-sm-4">
        <input name="biz_gstin" type="text" id="biz_gstin" class="form-control" value="<?= old('biz_gstin') ?>"/>
      </div>
    </div>

    <div class="row">
      <div class="col-sm-4"></div>
      <div class="col-sm-2">
        <button class="btn btn-primary" type="submit" name="submit">Submit</button>
      </div>
      <div class="col-sm-2">
        <button class="btn btn-default" type="reset" name="Reset">Reset</button>
      </div>
    </div>
  </form>

  <div class="row" style="margin-top:20px;">
    <div class="col-sm-12">
      (*) Items marked are mandatory
    </div>
  </div>
</div>

<div><?php // include "biz-footer.php"; ?></div>
</body>
</html>

