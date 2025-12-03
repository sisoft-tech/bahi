<?php
ob_start();
session_start();

include 'include/dbi.php';        // mysqli $conn – for legacy helpers
include 'include/mybiz-plib.php'; // expects mysqli-based helpers today
include 'include/dbo.php';        // PDO dbo()
include 'include/session.php';
include 'include/param-pos.php';

checksession();
$username_head = $_SESSION['login'] ?? '';

$ecomFeature = 'Y';
$debug       = 0;

$if_login = $username_head;

// Create PDO connection via your dbo() wrapper
try {
    $dbh = new dbo();   // assuming dbo extends PDO
} catch (Throwable $e) {
    die("Database connection error.");
}

// Show businesses where:
//  - user was original creator (user_added = email)  [migration support]
//  OR
//  - user has an active access row in biz_estab_user_access
$base_qry = "
    SELECT *
    FROM biz_establishment
    WHERE 
      user_added = :email1
      OR biz_id IN (
            SELECT biz_id
            FROM biz_estab_user_access ua
            WHERE ua.user_email = :email2
              AND ua.status = 'active'
        )
    ORDER BY dtm_added DESC
";

if ($debug) {
    echo "<pre>" . htmlspecialchars($base_qry, ENT_QUOTES, 'UTF-8') . "</pre>";
}

/**
 * Per-app subscription access using biz_subs_contract.prod_item_name (PDO version)
 *
 * @param PDO    $dbh
 * @param int    $biz_id
 * @param string $appCode  prod_item_name value, e.g. 'billing', 'ecom', 'godam'
 * @return array ['enabled' => bool, 'label' => string]
 */
function getBizAppAccess(PDO $dbh, int $biz_id, string $appCode): array {
    if (!$biz_id || $appCode === '') {
        // Fail-open for bad input (or you can return disabled)
        return ['enabled' => true, 'label' => 'Legacy (no plan)'];
    }

    // Find the most recent contract for that app for this business
    // Assumes prod_item_name stores codes like 'billing', 'ecom', 'godam'
    $sql = "
        SELECT subs_start_dt, subs_end_dt, subs_status, amt_notes
        FROM biz_subs_contract
        WHERE biz_id = :biz_id
          AND prod_item_name = :appCode
        ORDER BY subs_end_dt DESC
        LIMIT 1
    ";

    try {
        $stmt = $dbh->prepare($sql);
        $stmt->execute([
            ':biz_id'  => $biz_id,
            ':appCode' => $appCode,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // On SQL error, default to legacy behaviour
        return ['enabled' => true, 'label' => 'Legacy (no plan)'];
    }

    if (!$row) {
        // No contract yet => treat as legacy allowed (during migration)
        return ['enabled' => true, 'label' => 'Legacy (no plan)'];
    }

    $start_dt  = $row['subs_start_dt'];
    $end_dt    = $row['subs_end_dt'];
    $status    = trim((string)$row['subs_status']);
    $amt_notes = $row['amt_notes'];

    $today  = date('Y-m-d');

    // Enabled only if:
    //  - status is 'active'
    //  - AND we are within date range
    $inRange = ($start_dt <= $today && $end_dt >= $today);
    $enabled = ($status === 'active' && $inRange);

    $labelParts = [];
    if ($amt_notes !== null && $amt_notes !== '') {
        $labelParts[] = $amt_notes;
    }
    $labelParts[] = ucfirst($status);
    $labelParts[] = $start_dt . ' → ' . $end_dt;

    $label = implode(' / ', $labelParts);

    return ['enabled' => $enabled, 'label' => $label];
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml">

<head>
<title> Euphoria Bahi - My Business</title>
<link rel="shortcut icon" type="image/icon" href="image/icon-main.png"/>
<meta name="description" content="Business Classifieds/Listing for Local Business" />
<meta name="keywords" content="Business Classifieds, Free Business Listing" />
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
<META HTTP-EQUIV="PRAGMA" CONTENT="NO-CACHE">
<META HTTP-EQUIV="EXPIRES" CONTENT="0">

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">

<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">  
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />   

<style>
.row a{
  text-decoration: none;
  font-size: 16px;  
  font-weight:bold;
}
.badge-sub {
  display:inline-block;
  padding:2px 6px;
  border-radius:4px;
  font-size:11px;
}
.badge-sub-active { background:#d9fdd3; color:#155724; }   /* green-ish */
.badge-sub-inactive { background:#f8d7da; color:#721c24; } /* red-ish */
</style>
</head>

<body>
<?php include("biz-header.php"); ?>

<div class="container">

  <div class="row" style="margin-top:30px;">
    <div class="col-lg-8"><h3> My Businesses </h3></div>

    <div class="col-lg-2">
      <button type="button" onClick="window.location.assign('biz-mybusiness-add.php')">
        Add Business
      </button>
    </div>
    <div class="col-lg-2"></div>
  </div>

  <table class="table table-striped">
    <thead>
      <tr> 
        <th>#</th>
        <th>Business ID</th> 
        <th>Business Name</th>
        <th>Buisness Category</th>
<!--        <th>Buisness Details</th>               -->
        <th>Phone</th>
        <th>Email<br/>Website</th>
        <th>Address</th>
        <th>Manage Profile</th>
        <th>Billing Desktop</th>
        <th>e-Commerce</th>
        <th>Godam</th>
      </tr>
    </thead>

    <tbody>
      <?php
      $i = 1;

      try {
          $stmtBiz = $dbh->prepare($base_qry);
          $stmtBiz->execute([':email1' => $if_login, ':email2' => $if_login]);
      } catch (Throwable $e) {
          echo '<tr><td colspan="11">Error loading businesses.</td></tr>';
          $stmtBiz = null;
      }

      if (isset($stmtBiz) && $stmtBiz) {
          while ($row = $stmtBiz->fetch(PDO::FETCH_ASSOC)) {
              $bizId = (int)$row['biz_id'];
			  $bizName = $row['biz_name'] ;
			  
              // Role from biz_estab_user_access – legacy helper still using mysqli $conn

              $role = getBizUserRole($conn, $bizId, $if_login);

              // Migration rule:
              // Allow profile edit if:
              //  - role is owner or manager
              //  OR
              //  - this user originally created the business (user_added)

              $createdBy = $row['user_added']; // original creator email
              $canEditProfile = (
                  $role === 'owner' ||
                  $role === 'manager' ||
                  $createdBy === $if_login
              );

              // Per-app subscription access (prod_item_name values must match these codes)
              $billingAccess = getBizAppAccess($dbh, $bizId, 'billing'); // Billing Desktop
              $ecomAccess    = getBizAppAccess($dbh, $bizId, 'ecom');    // e-Commerce
              $godamAccess   = getBizAppAccess($dbh, $bizId, 'godam');   // Godam / warehouse
      ?>
      <tr>
        <td><?php echo $i; ?></td>
        <td><?php echo $bizId; ?></td>

        <td>
          <?php 
            $logo_img_loc = $row['biz_logo_image_loc'];
            if ($logo_img_loc != NULL) {
                echo "<img src='" . htmlspecialchars($logo_img_loc, ENT_QUOTES, 'UTF-8') . "' width='50px'> ";
            }
            echo htmlspecialchars($row['biz_name'], ENT_QUOTES, 'UTF-8');
          ?>
        </td>

        <td>
          <?php
            $bcat_id   = $row['bcat_id'];
            // legacy helper using mysqli connection
            $bcat_name = getCategoryName($conn, $bcat_id);
            echo htmlspecialchars($bcat_name, ENT_QUOTES, 'UTF-8');
          ?>
        </td>

<!--        <td><?php echo htmlspecialchars($row['biz_details'], ENT_QUOTES, 'UTF-8'); ?></td> -->

        <td>
          <?php
            echo htmlspecialchars($row['biz_phone1'], ENT_QUOTES, 'UTF-8');
            if (!empty($row['biz_phone2'])) {
                echo "<br/>" . htmlspecialchars($row['biz_phone2'], ENT_QUOTES, 'UTF-8');
            }
          ?>
        </td>

        <td>
          <?php
            echo htmlspecialchars($row['biz_email'], ENT_QUOTES, 'UTF-8') . "<br/>" .
                 htmlspecialchars($row['biz_website'], ENT_QUOTES, 'UTF-8');
          ?>
        </td>

        <td>
          <?php
            echo htmlspecialchars($row['biz_area'], ENT_QUOTES, 'UTF-8') . "<br/>" .
                 htmlspecialchars($row['biz_city'], ENT_QUOTES, 'UTF-8');
          ?>
        </td>
$bizName
        <!-- Manage Profile -->
        <td>
          <?php if ($canEditProfile): ?>
            <form action="biz-profile.php" method="POST">
              <input type="hidden" name="update_id" value="<?php echo $bizId; ?>"/>
              <input type="hidden" name="biz_name" value="<?php echo $bizName; ?>"/>
              <button class="blue btn-floating btn-large" type="submit">
                <span class="material-symbols-outlined">settings</span>
              </button>
            </form>
          <?php else: ?>
            <span class="text-muted">No profile access</span>
          <?php endif; ?>
        </td>

        <!-- Billing Desktop -->
        <td style="text-align:center;">
          <?php if ($billingAccess['enabled']): ?>
            <form action="bahi/pos-index.php" method="POST">
              <input type="hidden" name="biz_id" value="<?php echo $bizId; ?>"/>
              <input type="hidden" name="user_email" value="<?php echo htmlspecialchars($if_login, ENT_QUOTES, 'UTF-8'); ?>"/>
              <button class="btn-floating btn-large" type="submit" name="OWNER_POS">
                <span class="material-symbols-outlined">point_of_sale</span>
              </button>
            </form>
          <?php else: ?>
            <span class="text-muted">Subscription inactive</span>
          <?php endif; ?>
        </td>

        <!-- e-Commerce -->
        <td style="text-align:center;">
          <?php if ($ecomFeature === 'Y' && $ecomAccess['enabled']): ?>
            <form action="ecom/ecom-index.php" method="POST">
              <input type="hidden" name="biz_id" value="<?php echo $bizId; ?>"/>
              <input type="hidden" name="user_email" value="<?php echo htmlspecialchars($if_login, ENT_QUOTES, 'UTF-8'); ?>"/>
              <button class="btn-floating btn-large" type="submit" name="OWNER_POS">
                <span class="material-symbols-outlined">shopping_cart_checkout</span>
              </button>
            </form>
          <?php elseif ($ecomFeature !== 'Y'): ?>
            <span class="text-muted">N/A</span>
          <?php else: ?>
            <span class="text-muted">Subscription inactive</span>
          <?php endif; ?>
        </td>

        <!-- Godam -->
        <td style="text-align:center;">
          <?php if ($godamAccess['enabled']): ?>
            <form action="gdm/gdm-index.php" method="POST">
              <input type="hidden" name="biz_id" value="<?php echo $bizId; ?>"/>
              <input type="hidden" name="user_email" value="<?php echo htmlspecialchars($if_login, ENT_QUOTES, 'UTF-8'); ?>"/>
              <button class="btn-floating btn-large" type="submit" name="OWNER_POS">
                <span class="material-symbols-outlined">warehouse</span>
              </button>
            </form>
          <?php else: ?>
            <span class="text-muted">Subscription inactive</span>
          <?php endif; ?>
        </td>

      </tr>
      <?php
              $i++;
          }
      }
      ?>
    </tbody>
  </table>

</div>

<div><?php //include "biz-footer.php"; ?></div>
</body>
</html>
