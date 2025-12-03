<?php
ob_start();
session_start();

/**
 * Manage subscriptions for the logged-in admin.
 *
 * - Lists active contracts from biz_subs_contract (joined with biz_establishment for biz_name).
 * - Allows requesting conversion of an active trial contract to a paid plan (creates pending paid contract).
 * - Allows cancelling active trial/paid contracts, with CSRF checks and transactional updates.
 * - Allows adding new trial subscriptions for any owned business (other products).
 */

require 'include/dbo.php';
require 'include/session.php';

checksession();

$dbh = new dbo();
$username_head = $_SESSION['login'] ?? '';

// Email ID where upgrade requests should be notified
$subscriptionAdminEmail = 'subscription_admin@yourdomain.com'; // TODO: set real address

// ---------- CSRF ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Classify billing period as Monthly / Yearly / Custom based on dates.
 */
function classify_billing_period(?string $start, ?string $end): string {
    if (!$start || !$end) return 'Custom';

    $ds = DateTime::createFromFormat('Y-m-d', $start);
    $de = DateTime::createFromFormat('Y-m-d', $end);
    if (!$ds || !$de) return 'Custom';

    $diff = (int)$ds->diff($de)->days; // positive number of days

    if ($diff >= 27 && $diff <= 33) {
        return 'Monthly';
    } elseif ($diff >= 360 && $diff <= 370) {
        return 'Yearly';
    }
    return 'Custom';
}

$errors = [];
$notice = '';
$success = '';

// ---------- Resolve current admin_id from email ----------
$admin_id = null;
if ($username_head !== '') {
    try {
        $sa = $dbh->prepare("
            SELECT id
            FROM biz_admin_users
            WHERE admin_email = :email
              AND status = 'active'
            LIMIT 1
        ");
        $sa->execute([':email' => $username_head]);
        $ar = $sa->fetch(PDO::FETCH_ASSOC);
        if ($ar) {
            $admin_id = (int)$ar['id'];
        } else {
            $errors[] = 'Admin user not found or inactive.';
        }
    } catch (Throwable $e) {
        $errors[] = 'Error loading admin user.';
    }
} else {
    $errors[] = 'Not logged in (no email in session).';
}

// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !$errors) {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
        $errors[] = 'Invalid form token. Please refresh and try again.';
    } else {
        $action = $_POST['action'];

        if (!in_array($action, ['trial_to_paid', 'cancel', 'add_new_sub'], true)) {
            $errors[] = 'Unknown action requested.';
        }

        if (in_array($action, ['trial_to_paid', 'cancel'], true)) {
            $contract_id = isset($_POST['contract_id']) ? (int)$_POST['contract_id'] : 0;
            if ($contract_id <= 0) {
                $errors[] = 'Invalid contract selected.';
            }
        }
    }

    if (!$errors) {
        try {
            // ---- trial_to_paid / cancel existing contract ----
            if (in_array($action, ['trial_to_paid', 'cancel'], true)) {
                $dbh->beginTransaction();

                // Load the contract + its business (only for name), ensure it belongs to this admin
                $q = $dbh->prepare("
                    SELECT
                        c.contract_id,
                        c.biz_id,
                        c.cust_email,
                        c.prod_item_name,
                        c.subs_start_dt,
                        c.subs_end_dt,
                        c.subs_status AS contract_status,
                        c.subs_plan,                -- trial | paid | free
                        c.amt_notes,
                        c.invoice_notes,
                        c.is_renewal,
                        c.parent_contract_id,
                        b.biz_name
                    FROM biz_subs_contract c
                    JOIN biz_establishment b ON b.biz_id = c.biz_id
                    WHERE c.contract_id = :cid
                      AND c.cust_id     = :admin_id
                    FOR UPDATE
                ");
                $q->execute([
                    ':cid'      => $contract_id,
                    ':admin_id' => $admin_id,
                ]);
                $row = $q->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    throw new RuntimeException('Contract not found or not accessible to this user.');
                }

                $subs_plan      = $row['subs_plan'];       // trial | paid | free
                $contractStatus = $row['contract_status'];

                // ---- Action: trial -> paid (request) ----
                if ($action === 'trial_to_paid') {
                    if (!($subs_plan === 'trial' && $contractStatus === 'active')) {
                        throw new RuntimeException('This contract is not in an active trial state.');
                    }

                    // New period requested for paid plan (editable from modal)
                    $postedStart = $_POST['new_start'] ?? '';
                    $postedEnd   = $_POST['new_end']   ?? '';

                    $trialEnd = $row['subs_end_dt']; // existing trial end date (YYYY-MM-DD)

                    if ($postedStart === '') {
                        $postedStart = $trialEnd;
                    }
                    if ($postedEnd === '') {
                        // default = +1 year from trial end
                        $deTmp = DateTime::createFromFormat('Y-m-d', $trialEnd) ?: null;
                        if ($deTmp) {
                            $deTmp->modify('+1 year');
                            $postedEnd = $deTmp->format('Y-m-d');
                        }
                    }

                    $ds = DateTime::createFromFormat('Y-m-d', $postedStart) ?: false;
                    $de = DateTime::createFromFormat('Y-m-d', $postedEnd)   ?: false;

                    if (!$ds || !$de || $de <= $ds) {
                        throw new RuntimeException('Invalid new subscription dates. Please choose proper start and end dates.');
                    }

                    $newStart = $ds->format('Y-m-d');
                    $newEnd   = $de->format('Y-m-d');

                    // Create a *pending* paid contract; trial stays active until admin approves & activates
                    $insertPaid = $dbh->prepare("
                        INSERT INTO biz_subs_contract
                        (biz_id, cust_id, cust_email,
                         prod_item_name, prod_srl_no, prod_notes,
                         subs_start_dt, subs_end_dt, subs_status, subs_plan,
                         amt_notes, is_renewal, parent_contract_id,
                         updated_by, updated_dtm,
                         created_by, created_dtm, created_ip,
                         invoice_notes, receipt_status)
                        SELECT
                          c.biz_id,
                          c.cust_id,
                          c.cust_email,
                          c.prod_item_name,
                          c.prod_srl_no,
                          c.prod_notes,
                          :new_start1,
                          :new_end1,
                          'pending',
                          'paid',
                          CONCAT('Paid plan requested from ', :new_start2, ' to ', :new_end2, ' - ', COALESCE(c.amt_notes, '')),
                          'Y',
                          c.contract_id,
                          :updated_by,
                          NOW(),
                          :created_by,
                          NOW(),
                          :created_ip,
                          :invoice_notes,
                          'unpaid'
                        FROM biz_subs_contract c
                        WHERE c.contract_id = :cid
                    ");
                    $insertPaid->execute([
                        ':new_start1'    => $newStart,
                        ':new_end1'      => $newEnd,
                        ':new_start2'    => $newStart,
                        ':new_end2'      => $newEnd,
                        ':updated_by'    => $username_head ?: 'system',
                        ':created_by'    => $username_head ?: 'system',
                        ':created_ip'    => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                        ':invoice_notes' => 'Upgrade to paid requested via portal (awaiting payment confirmation)',
                        ':cid'           => $contract_id,
                    ]);

                    $newPaidId = (int)$dbh->lastInsertId();

                    // Commit DB changes first
                    $dbh->commit();
                    $success = 'Paid-plan upgrade requested. The admin will activate it after confirming your payment.';

                    // -------- Email notification to subscription admin (non-fatal if it fails) --------
                    try {
                        if (!empty($subscriptionAdminEmail) &&
                            filter_var($subscriptionAdminEmail, FILTER_VALIDATE_EMAIL)) {

                            $subject = '[Subscriptions] Paid plan request #' . $newPaidId;

                            $bodyLines   = [];
                            $bodyLines[] = 'A new paid-plan upgrade request has been submitted.';
                            $bodyLines[] = '';
                            $bodyLines[] = 'Customer email: ' . ($row['cust_email'] ?? $username_head);
                            $bodyLines[] = 'Business: ' . ($row['biz_name'] ?? '') . ' (biz_id: ' . ($row['biz_id'] ?? '') . ')';
                            $bodyLines[] = 'Product: ' . ($row['prod_item_name'] ?? '');
                            $bodyLines[] = 'Trial contract ID: ' . ($row['contract_id'] ?? '');
                            $bodyLines[] = 'Requested paid contract ID: ' . $newPaidId;
                            $bodyLines[] = 'Requested period: ' . $newStart . ' to ' . $newEnd;
                            $bodyLines[] = '';
                            $bodyLines[] = 'Please verify payment and activate this contract in the admin panel.';

                            $body    = implode("\r\n", $bodyLines);
                            $headers = "From: no-reply@yourdomain.com\r\n" .
                                       "Content-Type: text/plain; charset=UTF-8\r\n";

                            @mail($subscriptionAdminEmail, $subject, $body, $headers);
                        }
                    } catch (Throwable $eMail) {
                        // Do not break page if mail fails; optionally log error here
                    }

                // ---- Action: cancel (trial or paid) ----
                } elseif ($action === 'cancel') {
                    if ($contractStatus !== 'active') {
                        throw new RuntimeException('Only active contracts can be cancelled.');
                    }

                    // Cancel this contract
                    $updC = $dbh->prepare("
                        UPDATE biz_subs_contract
                        SET subs_status = 'cancelled',
                            updated_by  = :user,
                            updated_dtm = NOW()
                        WHERE contract_id = :cid
                    ");
                    $updC->execute([
                        ':user' => $username_head ?: 'system',
                        ':cid'  => $contract_id
                    ]);

                    $dbh->commit();
                    $success = 'Subscription cancelled successfully.';
                }

            // ---- Action: add_new_sub (create trial subscription for any owned biz) ----
            } elseif ($action === 'add_new_sub') {
                $dbh->beginTransaction();

                $biz_id        = isset($_POST['biz_id']) ? (int)$_POST['biz_id'] : 0;
                $prod_item     = trim($_POST['prod_item_name'] ?? '');
                // Only trial subscriptions can be created from this screen
                $subs_plan_new = 'trial';
                $startStr      = $_POST['subs_start_dt'] ?? '';
                $endStr        = $_POST['subs_end_dt']   ?? '';
                $prod_srl_no   = trim($_POST['prod_srl_no'] ?? '');
                $prod_notes    = trim($_POST['prod_notes'] ?? '');
                $amt_notes     = trim($_POST['amt_notes'] ?? '');
                $invoice_notes = trim($_POST['invoice_notes'] ?? '');

                if ($biz_id <= 0) {
                    throw new RuntimeException('Please select a business.');
                }
                if ($prod_item === '') {
                    throw new RuntimeException('Please select a product.');
                }
                if ($subs_plan_new !== 'trial') {
                    throw new RuntimeException('Invalid subscription plan.');
                }

                $ds = DateTime::createFromFormat('Y-m-d', $startStr) ?: false;
                $de = DateTime::createFromFormat('Y-m-d', $endStr)   ?: false;
                if (!$ds || !$de || $de <= $ds) {
                    throw new RuntimeException('Invalid subscription dates for new subscription.');
                }
                $startStr = $ds->format('Y-m-d');
                $endStr   = $de->format('Y-m-d');

                // Ensure this admin actually owns/has a relation with this biz
                $chk = $dbh->prepare("
                    SELECT 1
                    FROM biz_subs_contract
                    WHERE biz_id = :biz_id
                      AND cust_id = :admin_id
                    LIMIT 1
                ");
                $chk->execute([
                    ':biz_id'   => $biz_id,
                    ':admin_id' => $admin_id,
                ]);
                if (!$chk->fetchColumn()) {
                    throw new RuntimeException('You are not allowed to add subscriptions for this business.');
                }

                $ins = $dbh->prepare("
                    INSERT INTO biz_subs_contract
                    (biz_id, cust_id, cust_email,
                     prod_item_name, prod_srl_no, prod_notes,
                     subs_start_dt, subs_end_dt, subs_status, subs_plan,
                     amt_notes, is_renewal, parent_contract_id,
                     updated_by, updated_dtm,
                     created_by, created_dtm, created_ip,
                     invoice_notes, receipt_status)
                    VALUES
                    (:biz_id, :cust_id, :cust_email,
                     :prod_item_name, :prod_srl_no, :prod_notes,
                     :subs_start_dt, :subs_end_dt, 'active', :subs_plan,
                     :amt_notes, 'N', NULL,
                     :updated_by, NOW(),
                     :created_by, NOW(), :created_ip,
                     :invoice_notes, :receipt_status)
                ");
                $ins->execute([
                    ':biz_id'        => $biz_id,
                    ':cust_id'       => $admin_id,
                    ':cust_email'    => $username_head,
                    ':prod_item_name'=> $prod_item,
                    ':prod_srl_no'   => ($prod_srl_no !== '' ? $prod_srl_no : null),
                    ':prod_notes'    => ($prod_notes !== '' ? $prod_notes : null),
                    ':subs_start_dt' => $startStr,
                    ':subs_end_dt'   => $endStr,
                    ':subs_plan'     => $subs_plan_new,
                    ':amt_notes'     => ($amt_notes !== '' ? $amt_notes : null),
                    ':updated_by'    => $username_head ?: 'system',
                    ':created_by'    => $username_head ?: 'system',
                    ':created_ip'    => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                    ':invoice_notes' => ($invoice_notes !== '' ? $invoice_notes : null),
                    ':receipt_status'=> 'unpaid', // default: unpaid; no payment flow yet
                ]);

                $dbh->commit();
                $success = 'New trial subscription created successfully.';
            }

            // regenerate token after successful state change / creation (optional)
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $csrf_token = $_SESSION['csrf_token'];

        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

// ---------- Load active contracts for display ----------

$contracts = [];
$bizOptions = [];
if (!$errors && $admin_id !== null) {
    try {
        $list = $dbh->prepare("
            SELECT
              c.contract_id,
              c.biz_id,
              b.biz_name,
              c.prod_item_name,
              c.subs_plan,
              c.subs_status AS contract_status,
              c.subs_start_dt,
              c.subs_end_dt,
              c.amt_notes,
              c.invoice_notes
            FROM biz_subs_contract c
            JOIN biz_establishment b ON b.biz_id = c.biz_id
            WHERE c.cust_id    = :admin_id
              AND c.subs_status = 'active'
            ORDER BY b.biz_name ASC, c.prod_item_name ASC, c.subs_end_dt ASC
        ");
        $list->execute([':admin_id' => $admin_id]);
        $contracts = $list->fetchAll(PDO::FETCH_ASSOC);

        foreach ($contracts as $c) {
            $bid = (int)$c['biz_id'];
            if (!isset($bizOptions[$bid])) {
                $bizOptions[$bid] = $c['biz_name'];
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Error loading active contracts.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Subscriptions</title>
    <link rel="shortcut icon" type="image/icon" href="image/icon-main.png"/>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>

    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

    <style>
        .page-header { margin-top: 20px; }
        .table-actions form { display:inline-block; margin:0 2px; }
        .table-actions .btn { margin-bottom: 3px; }
    </style>
</head>
<body>
<?php include "biz-header.php"; ?>

<div class="container">
    <div class="page-header clearfix">
        <h3 class="pull-left">My Active Subscriptions</h3>
 <!--       <?php if (!empty($bizOptions)): ?> -->
            <button type="button"
                    class="btn btn-primary btn-sm pull-right"
                    id="btnOpenNewSub"
                    style="margin-top:60px;">
                Add Subscription
            </button>
<!--        <?php endif; ?>  -->
        <p class="text-muted" style="clear:both;">
            Request a paid-plan upgrade for active trials, cancel subscriptions, or start a new trial for another product.
        </p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul style="margin-bottom:0;">
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($contracts)): ?>
        <div class="alert alert-info">
            No active subscriptions found for your account.
        </div>
    <?php else: ?>
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Product</th>
                    <th>Plan</th>
                    <th>Contract Status</th>
                    <th>Period</th>
                    <th>Notes</th>
                    <th>Invoice Ref</th>
                    <th style="width:260px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($contracts as $c): ?>
                <?php
                    $plan           = $c['subs_plan'];        // trial | paid | free
                    $contractStatus = $c['contract_status'];  // active | cancelled | expired
                    $isTrial        = ($plan === 'trial');
                    $isPaid         = ($plan === 'paid');
                    $periodLabel    = classify_billing_period($c['subs_start_dt'], $c['subs_end_dt']);
                ?>
                <tr>
                    <td><?= e($c['biz_name']) ?></td>
                    <td><?= e($c['prod_item_name']) ?></td>
                    <td><?= e(ucfirst($plan)) ?></td>
                    <td><?= e(ucfirst($contractStatus)) ?></td>
                    <td>
                        <?= e($c['subs_start_dt']) ?> to <?= e($c['subs_end_dt']) ?><br>
                        <span class="label label-info"><?= e($periodLabel) ?></span>
                    </td>
                    <td><?= e($c['amt_notes']) ?></td>
                    <td><?= e($c['invoice_notes']) ?></td>
                    <td class="table-actions">
                        <?php if ($contractStatus === 'active'): ?>
                            <?php if ($isTrial): ?>
                                <!-- Trial -> Paid (request; open modal, with editable dates) -->
                                <form method="post" class="js-sub-form" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"/>
                                    <input type="hidden" name="contract_id" value="<?= (int)$c['contract_id'] ?>"/>
                                    <input type="hidden" name="action" value="trial_to_paid"/>
                                    <!-- these will be filled by modal JS before submit -->
                                    <input type="hidden" name="new_start" value=""/>
                                    <input type="hidden" name="new_end" value=""/>

                                    <button type="button"
                                            class="btn btn-xs btn-success js-open-sub-modal"
                                            data-mode="trial_to_paid"
                                            data-biz="<?= e($c['biz_name']) ?>"
                                            data-prod="<?= e($c['prod_item_name']) ?>"
                                            data-plan="<?= e($plan) ?>"
                                            data-period="<?= e($c['subs_start_dt'] . ' to ' . $c['subs_end_dt']) ?>"
                                            data-start="<?= e($c['subs_start_dt']) ?>"
                                            data-end="<?= e($c['subs_end_dt']) ?>">
                                        Request Paid Plan
                                    </button>
                                </form>

                                <!-- Trial -> Cancel (modal confirm, no dates) -->
                                <form method="post" class="js-sub-form" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"/>
                                    <input type="hidden" name="contract_id" value="<?= (int)$c['contract_id'] ?>"/>
                                    <input type="hidden" name="action" value="cancel"/>

                                    <button type="button"
                                            class="btn btn-xs btn-danger js-open-sub-modal"
                                            data-mode="cancel_trial"
                                            data-biz="<?= e($c['biz_name']) ?>"
                                            data-prod="<?= e($c['prod_item_name']) ?>"
                                            data-plan="<?= e($plan) ?>"
                                            data-period="<?= e($c['subs_start_dt'] . ' to ' . $c['subs_end_dt']) ?>"
                                            data-start="<?= e($c['subs_start_dt']) ?>"
                                            data-end="<?= e($c['subs_end_dt']) ?>">
                                        Cancel Trial
                                    </button>
                                </form>

                            <?php elseif ($isPaid): ?>
                                <!-- Paid -> Cancel (modal confirm, no dates) -->
                                <form method="post" class="js-sub-form" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"/>
                                    <input type="hidden" name="contract_id" value="<?= (int)$c['contract_id'] ?>"/>
                                    <input type="hidden" name="action" value="cancel"/>

                                    <button type="button"
                                            class="btn btn-xs btn-warning js-open-sub-modal"
                                            data-mode="cancel_paid"
                                            data-biz="<?= e($c['biz_name']) ?>"
                                            data-prod="<?= e($c['prod_item_name']) ?>"
                                            data-plan="<?= e($plan) ?>"
                                            data-period="<?= e($c['subs_start_dt'] . ' to ' . $c['subs_end_dt']) ?>"
                                            data-start="<?= e($c['subs_start_dt']) ?>"
                                            data-end="<?= e($c['subs_end_dt']) ?>">
                                        Cancel Subscription
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted">No actions</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">No actions</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Confirmation Modal (convert/cancel) -->
<div class="modal fade" id="subConfirmModal" tabindex="-1" role="dialog" aria-labelledby="subConfirmLabel">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal"
                aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="subConfirmLabel">Confirm Action</h4>
      </div>
      <div class="modal-body">
        <p><strong>Business:</strong> <span id="mBiz"></span></p>
        <p><strong>Product:</strong> <span id="mProd"></span></p>
        <p><strong>Current Plan:</strong> <span id="mPlan"></span></p>
        <p><strong>Current Period:</strong> <span id="mPeriod"></span></p>

        <div id="mNewDatesBlock" style="display:none; margin-top:10px;">
          <hr/>
          <p><strong>New Paid Period (editable):</strong></p>
          <div class="form-inline">
            <div class="form-group">
              <label for="mNewStart">Start:&nbsp;</label>
              <input type="date" id="mNewStart" class="form-control input-sm">
            </div>
            &nbsp;&nbsp;
            <div class="form-group">
              <label for="mNewEnd">End:&nbsp;</label>
              <input type="date" id="mNewEnd" class="form-control input-sm">
            </div>
          </div>
          <p class="help-block">
              Default is trial end as start, and one year later as end. You can adjust these.
          </p>
          <p>
              <strong>Detected Period:</strong>
              <span id="mPeriodType" class="label label-info">–</span>
          </p>
        </div>

        <p class="text-warning" id="mWarning" style="margin-top:10px;"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" id="confirmSubActionBtn">Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- New Subscription Modal (header button) -->
<div class="modal fade" id="newSubModal" tabindex="-1" role="dialog" aria-labelledby="newSubLabel">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post" id="newSubForm">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal"
                  aria-label="Close"><span aria-hidden="true">&times;</span></button>
          <h4 class="modal-title" id="newSubLabel">Add New Subscription</h4>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"/>
          <input type="hidden" name="action" value="add_new_sub"/>

          <div class="form-group">
            <label for="newBizId">Business</label>
            <select name="biz_id" id="newBizId" class="form-control">
              <option value="">-- Select Business --</option>
              <?php foreach ($bizOptions as $bid => $bname): ?>
                <option value="<?= (int)$bid ?>"><?= e($bname) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="newProdItem">Product</label>
            <select name="prod_item_name" id="newProdItem" class="form-control">
              <option value="">-- Select Product --</option>
              <option value="Bahi">Bahi Desktop</option>
              <option value="ecom">e-Commerce</option>
              <option value="godam">Godam</option>
            </select>
          </div>

          <div class="form-group">
            <label for="newPlan">Plan</label>
            <select id="newPlan" class="form-control" disabled>
              <option value="trial" selected>Trial (1-month default)</option>
            </select>
            <input type="hidden" name="subs_plan" value="trial">
            <p class="help-block">
              Only trial subscriptions can be started here. Paid plans are activated by the admin after payment.
            </p>
          </div>

          <div class="form-group">
            <label>Subscription Period</label>
            <div class="form-inline">
              <div class="form-group">
                <label for="newSubsStart">Start:&nbsp;</label>
                <input type="date" name="subs_start_dt" id="newSubsStart" class="form-control input-sm">
              </div>
              &nbsp;&nbsp;
              <div class="form-group">
                <label for="newSubsEnd">End:&nbsp;</label>
                <input type="date" name="subs_end_dt" id="newSubsEnd" class="form-control input-sm">
              </div>
            </div>
            <p class="help-block">
              Default is today as start and one month later as end. You can adjust these.
            </p>
            <p>
              <strong>Detected Period:</strong>
              <span id="newSubPeriodType" class="label label-info">–</span>
            </p>
          </div>

          <div class="form-group">
            <label for="newProdSrl">Product Version / Serial (optional)</label>
            <input type="text" name="prod_srl_no" id="newProdSrl" class="form-control">
          </div>

          <div class="form-group">
            <label for="newProdNotes">Product Notes (optional)</label>
            <textarea name="prod_notes" id="newProdNotes" class="form-control" rows="2"></textarea>
          </div>

          <div class="form-group">
            <label for="newAmtNotes">Amount Notes (optional)</label>
            <input type="text" name="amt_notes" id="newAmtNotes" class="form-control">
          </div>

          <div class="form-group">
            <label for="newInvoiceNotes">Invoice Reference / Notes (optional)</label>
            <input type="text" name="invoice_notes" id="newInvoiceNotes" class="form-control">
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary" id="saveNewSubBtn">Save Subscription</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function($) {
    var pendingForm = null;
    var pendingMode = null;

    function toYMDLocal(d) {
        var year  = d.getFullYear();
        var month = (d.getMonth() + 1).toString().padStart(2, '0');
        var day   = d.getDate().toString().padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function formatDatePlusOneYear(dateStr) {
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) {
            return '';
        }
        d.setFullYear(d.getFullYear() + 1);
        return toYMDLocal(d);
    }

    function detectPeriodType(startStr, endStr) {
        if (!startStr || !endStr) return 'Custom';
        var s = new Date(startStr);
        var e = new Date(endStr);
        if (isNaN(s.getTime()) || isNaN(e.getTime())) return 'Custom';

        var diffMs   = e - s;
        var diffDays = diffMs / (1000 * 60 * 60 * 24);

        if (diffDays >= 27 && diffDays <= 33) return 'Monthly';
        if (diffDays >= 360 && diffDays <= 370) return 'Yearly';
        return 'Custom';
    }

    function updateModalPeriodType() {
        var startStr = $('#mNewStart').val();
        var endStr   = $('#mNewEnd').val();
        var label    = detectPeriodType(startStr, endStr);
        $('#mPeriodType').text(label);
    }

    function updateNewSubPeriodType() {
        var startStr = $('#newSubsStart').val();
        var endStr   = $('#newSubsEnd').val();
        var label    = detectPeriodType(startStr, endStr);
        $('#newSubPeriodType').text(label);
    }

    // Existing actions (convert/cancel) modal
    $('.js-open-sub-modal').on('click', function (e) {
        e.preventDefault();

        pendingForm = this.form;
        pendingMode = $(this).data('mode');

        var biz    = $(this).data('biz')    || '';
        var prod   = $(this).data('prod')   || '';
        var plan   = $(this).data('plan')   || '';
        var period = $(this).data('period') || '';
        var start  = $(this).data('start')  || '';
        var end    = $(this).data('end')    || '';

        $('#mBiz').text(biz);
        $('#mProd').text(prod);
        $('#mPlan').text(plan ? plan.charAt(0).toUpperCase() + plan.slice(1) : '');
        $('#mPeriod').text(period);

        $('#mWarning').text('');
        $('#mNewDatesBlock').hide();
        $('#mNewStart').val('');
        $('#mNewEnd').val('');
        $('#mPeriodType').text('–');

        if (pendingMode === 'trial_to_paid') {
            $('#subConfirmLabel').text('Request Conversion to Paid Plan');

            var defaultStart = end || start;
            var defaultEnd   = formatDatePlusOneYear(defaultStart);

            $('#mNewStart').val(defaultStart);
            $('#mNewEnd').val(defaultEnd);
            $('#mNewDatesBlock').show();
            updateModalPeriodType();

            $('#mWarning').text(
                'This will submit a request for a paid subscription. ' +
                'The admin will activate it after confirming payment. ' +
                'Your trial will remain active until the upgrade is processed.'
            );
        } else if (pendingMode === 'cancel_trial') {
            $('#subConfirmLabel').text('Cancel Trial Subscription');
            $('#mWarning').text('This will cancel the trial subscription and you will lose trial access.');
        } else if (pendingMode === 'cancel_paid') {
            $('#subConfirmLabel').text('Cancel Paid Subscription');
            $('#mWarning').text('This will cancel the paid subscription. Any refunds must be handled separately.');
        } else {
            $('#subConfirmLabel').text('Confirm Action');
        }

        $('#subConfirmModal').modal('show');
    });

    // Whenever user edits dates in convert-to-paid modal
    $('#mNewStart, #mNewEnd').on('change', updateModalPeriodType);

    $('#confirmSubActionBtn').on('click', function () {
        if (!pendingForm) return;

        if (pendingMode === 'trial_to_paid') {
            var newStart = $('#mNewStart').val() || '';
            var newEnd   = $('#mNewEnd').val()   || '';

            if (!newStart || !newEnd) {
                alert('Please fill both start and end dates.');
                return;
            }

            $(pendingForm).find('input[name="new_start"]').val(newStart);
            $(pendingForm).find('input[name="new_end"]').val(newEnd);
        }

        pendingForm.submit();
        pendingForm = null;
        pendingMode = null;
    });

    // Header "Add Subscription" button -> open newSubModal
    $('#btnOpenNewSub').on('click', function(e) {
        e.preventDefault();

        // Reset form
        $('#newBizId').val('');
        $('#newProdItem').val('');
        $('#newPlan').val('trial'); // disabled, but keep consistent

        var today        = new Date();
        var todayStr     = toYMDLocal(today);
        var nextMonth    = new Date(today);
        nextMonth.setMonth(today.getMonth() + 1);
        var nextMonthStr = toYMDLocal(nextMonth);

        $('#newSubsStart').val(todayStr);
        $('#newSubsEnd').val(nextMonthStr);
        updateNewSubPeriodType();

        $('#newProdSrl').val('');
        $('#newProdNotes').val('');
        $('#newAmtNotes').val('');
        $('#newInvoiceNotes').val('');

        $('#newSubModal').modal('show');
    });

    $('#newSubsStart, #newSubsEnd').on('change', updateNewSubPeriodType);

    // Basic front-end validation for new subscription
    $('#newSubForm').on('submit', function(e) {
        var bizId  = $('#newBizId').val();
        var prod   = $('#newProdItem').val();
        var start  = $('#newSubsStart').val();
        var end    = $('#newSubsEnd').val();

        if (!bizId) {
            alert('Please select a business.');
            e.preventDefault();
            return;
        }
        if (!prod) {
            alert('Please select a product.');
            e.preventDefault();
            return;
        }
        if (!start || !end) {
            alert('Please fill both start and end dates.');
            e.preventDefault();
            return;
        }
        // server side does deeper validation
    });

})(jQuery);
</script>

<?php // include "biz-footer.php"; ?>
</body>
</html>
