<?php
ob_start();
session_start();

require 'include/dbo.php';
require 'include/session.php';

checksession();

$dbh = new dbo();
$username_head = $_SESSION['login'] ?? '';

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

$errors   = [];
$success  = '';
$notice   = '';
$editItem = null;

// ---------- Handle POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
        $errors[] = 'Invalid form token. Please refresh and try again.';
    } else {
        $action = $_POST['action'];

        if ($action === 'update') {
            // ---------- Update existing contract ----------
            $contract_id = isset($_POST['contract_id']) ? (int)$_POST['contract_id'] : 0;
            if ($contract_id <= 0) {
                $errors[] = 'Invalid contract id.';
            } else {
                // Collect editable fields
                $prod_item_name = trim($_POST['prod_item_name'] ?? '');
                $prod_srl_no    = trim($_POST['prod_srl_no'] ?? '');
                $prod_notes     = trim($_POST['prod_notes'] ?? '');
                $subs_start_dt  = trim($_POST['subs_start_dt'] ?? '');
                $subs_end_dt    = trim($_POST['subs_end_dt'] ?? '');
                $subs_status    = trim($_POST['subs_status'] ?? 'active');
                $subs_plan      = trim($_POST['subs_plan'] ?? '');
                $amt_notes      = trim($_POST['amt_notes'] ?? '');
                $is_renewal     = trim($_POST['is_renewal'] ?? 'N');
                $parent_contract_id = trim($_POST['parent_contract_id'] ?? '');
                $invoice_notes  = trim($_POST['invoice_notes'] ?? '');
                $receipt_status = trim($_POST['receipt_status'] ?? '');

                // Simple validation
                if ($prod_item_name === '') {
                    $errors[] = 'Product/Plan code is required.';
                }

                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $subs_start_dt)) {
                    $errors[] = 'Subscription start date must be in YYYY-MM-DD format.';
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $subs_end_dt)) {
                    $errors[] = 'Subscription end date must be in YYYY-MM-DD format.';
                } else {
                    $ds = DateTime::createFromFormat('Y-m-d', $subs_start_dt);
                    $de = DateTime::createFromFormat('Y-m-d', $subs_end_dt);
                    if ($ds && $de && $de <= $ds) {
                        $errors[] = 'End date must be after start date.';
                    }
                }

                // Allow pending in addition to active/cancelled/expired
                if (!in_array($subs_status, ['active','cancelled','expired','pending'], true)) {
                    $errors[] = 'Invalid subscription status. Allowed: active, cancelled, expired, pending.';
                }

                if (!in_array($subs_plan, ['trial','free','paid'], true)) {
                    $errors[] = 'Invalid subscription plan. Allowed: trial, free, paid.';
                }

                if (!in_array($is_renewal, ['Y','N'], true)) {
                    $errors[] = 'Invalid is_renewal value. Allowed: Y or N.';
                }
                if ($parent_contract_id !== '' && !ctype_digit($parent_contract_id)) {
                    $errors[] = 'Parent contract id must be numeric or left blank.';
                }

                if ($receipt_status === '') {
                    $errors[] = 'Receipt status is required.';
                }

                if (!$errors) {
                    try {
                        $dbh->beginTransaction();

                        // Ensure contract exists and lock it
                        $check = $dbh->prepare("
                            SELECT *
                            FROM biz_subs_contract
                            WHERE contract_id = :cid
                            FOR UPDATE
                        ");
                        $check->execute([':cid' => $contract_id]);
                        $existing = $check->fetch(PDO::FETCH_ASSOC);
                        if (!$existing) {
                            throw new RuntimeException('Contract not found.');
                        }

                        $oldStatus       = $existing['subs_status'];
                        $oldPlan         = $existing['subs_plan'];
                        $oldParentId     = $existing['parent_contract_id'];
                        $oldIsRenewal    = $existing['is_renewal'];

                        $parent_id = ($parent_contract_id === '') ? null : (int)$parent_contract_id;

                        $upd = $dbh->prepare("
                            UPDATE biz_subs_contract
                            SET
                                prod_item_name     = :prod_item_name,
                                prod_srl_no        = :prod_srl_no,
                                prod_notes         = :prod_notes,
                                subs_start_dt      = :subs_start_dt,
                                subs_end_dt        = :subs_end_dt,
                                subs_status        = :subs_status,
                                subs_plan          = :subs_plan,
                                amt_notes          = :amt_notes,
                                is_renewal         = :is_renewal,
                                parent_contract_id = :parent_contract_id,
                                invoice_notes      = :invoice_notes,
                                receipt_status     = :receipt_status,
                                updated_by         = :updated_by,
                                updated_dtm        = NOW()
                            WHERE contract_id = :contract_id
                        ");
                        $upd->execute([
                            ':prod_item_name'     => $prod_item_name,
                            ':prod_srl_no'        => $prod_srl_no !== '' ? $prod_srl_no : null,
                            ':prod_notes'         => $prod_notes !== '' ? $prod_notes : null,
                            ':subs_start_dt'      => $subs_start_dt,
                            ':subs_end_dt'        => $subs_end_dt,
                            ':subs_status'        => $subs_status,
                            ':subs_plan'          => $subs_plan,
                            ':amt_notes'          => $amt_notes !== '' ? $amt_notes : null,
                            ':is_renewal'         => $is_renewal,
                            ':parent_contract_id' => $parent_id,
                            ':invoice_notes'      => $invoice_notes !== '' ? $invoice_notes : null,
                            ':receipt_status'     => $receipt_status,
                            ':updated_by'         => $username_head ?: 'system',
                            ':contract_id'        => $contract_id
                        ]);

                        // If admin is converting a pending paid renewal to active, auto-expire parent trial
                        if (
                            $oldStatus === 'pending' &&
                            $subs_status === 'active' &&
                            $subs_plan === 'paid' &&
                            $is_renewal === 'Y' &&
                            $parent_id !== null
                        ) {
                            $expireParent = $dbh->prepare("
                                UPDATE biz_subs_contract
                                SET subs_status = 'expired',
                                    updated_by  = :updated_by,
                                    updated_dtm = NOW()
                                WHERE contract_id = :parent_id
                                  AND subs_status = 'active'
                            ");
                            $expireParent->execute([
                                ':updated_by' => $username_head ?: 'system',
                                ':parent_id'  => $parent_id
                            ]);
                        }

                        $dbh->commit();
                        $success = 'Contract updated successfully.';

                        // refresh CSRF for safety
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        $csrf_token = $_SESSION['csrf_token'];

                    } catch (Throwable $e) {
                        if ($dbh->inTransaction()) {
                            $dbh->rollBack();
                        }
                        $errors[] = 'Error while updating contract: '.$e->getMessage();
                    }
                }
            }

        } elseif ($action === 'add_new_sub') {
            // ---------- Create new contract ----------
            $biz_id        = isset($_POST['biz_id']) ? (int)$_POST['biz_id'] : 0;
            $cust_id       = isset($_POST['cust_id']) ? (int)$_POST['cust_id'] : 0;
            $cust_email    = trim($_POST['cust_email'] ?? '');
            $prod_item_name= trim($_POST['prod_item_name'] ?? '');
            $subs_plan     = trim($_POST['subs_plan'] ?? 'paid');
            $subs_start_dt = trim($_POST['subs_start_dt'] ?? '');
            $subs_end_dt   = trim($_POST['subs_end_dt'] ?? '');
            $prod_srl_no   = trim($_POST['prod_srl_no'] ?? '');
            $prod_notes    = trim($_POST['prod_notes'] ?? '');
            $amt_notes     = trim($_POST['amt_notes'] ?? '');
            $invoice_notes = trim($_POST['invoice_notes'] ?? '');
            $receipt_status= trim($_POST['receipt_status'] ?? 'unpaid');

            // Validation
            if ($biz_id <= 0) {
                $errors[] = 'Please select a business.';
            }
            if ($cust_id <= 0) {
                $errors[] = 'Customer id is required and must be numeric.';
            }
            if ($cust_email === '') {
                $errors[] = 'Customer email is required.';
            }
            if ($prod_item_name === '') {
                $errors[] = 'Product/Plan code is required.';
            }
            if (!in_array($subs_plan, ['trial','free','paid'], true)) {
                $errors[] = 'Invalid subscription plan. Allowed: trial, free, paid.';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $subs_start_dt)) {
                $errors[] = 'Subscription start date must be in YYYY-MM-DD format.';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $subs_end_dt)) {
                $errors[] = 'Subscription end date must be in YYYY-MM-DD format.';
            } else {
                $ds = DateTime::createFromFormat('Y-m-d', $subs_start_dt);
                $de = DateTime::createFromFormat('Y-m-d', $subs_end_dt);
                if ($ds && $de && $de <= $ds) {
                    $errors[] = 'End date must be after start date.';
                }
            }
            if ($receipt_status === '') {
                $errors[] = 'Receipt status is required.';
            }

            if (!$errors) {
                try {
                    $dbh->beginTransaction();

                    // Optionally ensure business exists
                    $chkBiz = $dbh->prepare("SELECT biz_id FROM biz_establishment WHERE biz_id = :bid");
                    $chkBiz->execute([':bid' => $biz_id]);
                    if (!$chkBiz->fetch(PDO::FETCH_ASSOC)) {
                        throw new RuntimeException('Business not found for given biz_id.');
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
                        ':cust_id'       => $cust_id,
                        ':cust_email'    => $cust_email,
                        ':prod_item_name'=> $prod_item_name,
                        ':prod_srl_no'   => ($prod_srl_no !== '' ? $prod_srl_no : null),
                        ':prod_notes'    => ($prod_notes !== '' ? $prod_notes : null),
                        ':subs_start_dt' => $subs_start_dt,
                        ':subs_end_dt'   => $subs_end_dt,
                        ':subs_plan'     => $subs_plan,
                        ':amt_notes'     => ($amt_notes !== '' ? $amt_notes : null),
                        ':updated_by'    => $username_head ?: 'system',
                        ':created_by'    => $username_head ?: 'system',
                        ':created_ip'    => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                        ':invoice_notes' => ($invoice_notes !== '' ? $invoice_notes : null),
                        ':receipt_status'=> $receipt_status,
                    ]);

                    $dbh->commit();
                    $success = 'New subscription contract created successfully.';

                    // refresh CSRF
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $csrf_token = $_SESSION['csrf_token'];

                } catch (Throwable $e) {
                    if ($dbh->inTransaction()) {
                        $dbh->rollBack();
                    }
                    $errors[] = 'Error while creating contract: '.$e->getMessage();
                }
            }
        } else {
            $errors[] = 'Unknown action.';
        }
    }
}

// ---------- If a contract_id is passed via GET, load for editing ----------
if (isset($_GET['contract_id']) && ctype_digit($_GET['contract_id'])) {
    $cid = (int)$_GET['contract_id'];
    if ($cid > 0) {
        try {
            $stmt = $dbh->prepare("
                SELECT c.*, b.biz_name
                FROM biz_subs_contract c
                JOIN biz_establishment b ON b.biz_id = c.biz_id
                WHERE c.contract_id = :cid
            ");
            $stmt->execute([':cid' => $cid]);
            $editItem = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $errors[] = 'Error loading contract for edit.';
        }
    }
}

// ---------- Load list of all contracts for all businesses ----------
$contracts   = [];
$bizOptions  = [];
try {
    $sqlList = "
        SELECT
            c.contract_id,
            c.biz_id,
            c.cust_id,
            b.biz_name,
            c.prod_item_name,
            c.subs_plan,
            c.subs_status,
            c.subs_start_dt,
            c.subs_end_dt,
            c.amt_notes,
            c.invoice_notes
        FROM biz_subs_contract c
        JOIN biz_establishment b ON b.biz_id = c.biz_id
        ORDER BY c.subs_end_dt DESC, b.biz_name ASC, c.contract_id DESC
    ";
    $stmtList = $dbh->query($sqlList);
    $contracts = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    foreach ($contracts as $c) {
        $bid = (int)$c['biz_id'];
        if (!isset($bizOptions[$bid])) {
            $bizOptions[$bid] = $c['biz_name'];
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Error loading contracts list.';
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>View & Edit Subscription Contracts</title>
    <link rel="shortcut icon" type="image/icon" href="image/icon-main.png"/>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>

    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

    <style>
        .page-header { margin-top: 20px; }
        .table-condensed th, .table-condensed td { font-size: 13px; }
        .edit-panel { margin-top: 20px; }
        .form-group label { font-weight: bold; }
    </style>
</head>
<body>
<?php include "biz-header.php"; ?>

<div class="container">
    <div class="page-header clearfix">
        <h3 class="pull-left">Subscription Contracts (All Businesses)</h3>
        <?php if (!empty($bizOptions)): ?>
            <button type="button"
                    class="btn btn-primary btn-sm pull-right"
                    id="btnOpenNewSub"
                    style="margin-top:20px;">
                Add Subscription
            </button>
        <?php endif; ?>
        <p class="text-muted" style="clear:both;">
            View and update subscription contracts for all businesses. You can also add new subscriptions.
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

    <!-- Contracts list -->
    <div class="panel panel-default">
        <div class="panel-heading">
            <strong>All Contracts</strong>
        </div>
        <div class="panel-body" style="overflow-x:auto;">
            <?php if (empty($contracts)): ?>
                <p class="text-muted">No contracts found.</p>
            <?php else: ?>
                <table class="table table-bordered table-striped table-condensed">
                    <thead>
                    <tr>
                        <th>Contract ID</th>
                        <th>Business</th>
                        <th>biz_id</th>
                        <th>cust_id</th>
                        <th>Product</th>
                        <th>Plan</th>
                        <th>Contract Status</th>
                        <th>Period</th>
                        <th>Amount Notes</th>
                        <th>Invoice Notes</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($contracts as $c): ?>
                        <?php
                            $periodLabel = classify_billing_period($c['subs_start_dt'], $c['subs_end_dt']);
                        ?>
                        <tr>
                            <td><?= (int)$c['contract_id'] ?></td>
                            <td><?= e($c['biz_name']) ?></td>
                            <td><?= (int)$c['biz_id'] ?></td>
                            <td><?= (int)$c['cust_id'] ?></td>
                            <td><?= e($c['prod_item_name']) ?></td>
                            <td><?= e($c['subs_plan']) ?></td>
                            <td><?= e($c['subs_status']) ?></td>
                            <td>
                                <?= e($c['subs_start_dt']) ?> to <?= e($c['subs_end_dt']) ?><br>
                                <span class="label label-info"><?= e($periodLabel) ?></span>
                            </td>
                            <td><?= e($c['amt_notes']) ?></td>
                            <td><?= e($c['invoice_notes']) ?></td>
                            <td>
                                <a href="?contract_id=<?= (int)$c['contract_id'] ?>" class="btn btn-xs btn-primary">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit form -->
    <div class="edit-panel">
        <div class="panel panel-info">
            <div class="panel-heading">
                <strong>Edit Contract</strong>
            </div>
            <div class="panel-body">
                <?php if (!$editItem): ?>
                    <p class="text-muted">
                        Select a contract from the table above and click <strong>Edit</strong>.
                    </p>
                <?php else: ?>
                    <form method="post" class="form-horizontal">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"/>
                        <input type="hidden" name="action" value="update"/>
                        <input type="hidden" name="contract_id" value="<?= (int)$editItem['contract_id'] ?>"/>

                        <div class="form-group">
                            <label class="col-sm-2 control-label">Contract ID</label>
                            <div class="col-sm-4">
                                <p class="form-control-static">
                                    <?= (int)$editItem['contract_id'] ?>
                                </p>
                            </div>
                            <label class="col-sm-2 control-label">Business</label>
                            <div class="col-sm-4">
                                <p class="form-control-static">
                                    <?= e($editItem['biz_name']) ?> (biz_id: <?= (int)$editItem['biz_id'] ?>)
                                </p>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-2 control-label">cust_id</label>
                            <div class="col-sm-4">
                                <p class="form-control-static">
                                    <?= (int)$editItem['cust_id'] ?>
                                </p>
                            </div>
                            <label class="col-sm-2 control-label">Customer Email</label>
                            <div class="col-sm-4">
                                <p class="form-control-static">
                                    <?= e($editItem['cust_email']) ?>
                                </p>
                            </div>
                        </div>

                        <hr/>

                        <div class="form-group">
                            <label class="col-sm-2 control-label" for="prod_item_name">Product / Plan Code</label>
                            <div class="col-sm-4">
                                <input type="text" name="prod_item_name" id="prod_item_name"
                                       class="form-control"
                                       value="<?= e($editItem['prod_item_name']) ?>" required/>
                                <p class="help-block">e.g. Bahi, ecom, godam</p>
                            </div>

                            <label class="col-sm-2 control-label" for="prod_srl_no">Product Srl No</label>
                            <div class="col-sm-4">
                                <input type="text" name="prod_srl_no" id="prod_srl_no"
                                       class="form-control"
                                       value="<?= e($editItem['prod_srl_no']) ?>"/>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-2 control-label" for="prod_notes">Product Notes</label>
                            <div class="col-sm-10">
                                <textarea name="prod_notes" id="prod_notes"
                                          class="form-control" rows="2"><?= e($editItem['prod_notes']) ?></textarea>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-2 control-label" for="subs_start_dt">Start Date</label>
                            <div class="col-sm-4">
                                <input type="date" name="subs_start_dt" id="subs_start_dt"
                                       class="form-control"
                                       value="<?= e($editItem['subs_start_dt']) ?>" required/>
                            </div>

                            <label class="col-sm-2 control-label" for="subs_end_dt">End Date</label>
                            <div class="col-sm-4">
                                <input type="date" name="subs_end_dt" id="subs_end_dt"
                                       class="form-control"
                                       value="<?= e($editItem['subs_end_dt']) ?>" required/>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-2 control-label" for="subs_plan">Plan</label>
                            <div class="col-sm-4">
                                <select name="subs_plan" id="subs_plan" class="form-control" required>
                                    <?php
                                    $curPlan = $editItem['subs_plan'];
                                    $allowedPlans = ['trial','free','paid'];
                                    foreach ($allowedPlans as $pl) {
                                        $sel = ($curPlan === $pl) ? 'selected' : '';
                                        echo '<option value="'.e($pl).'" '.$sel.'>'.ucfirst($pl).'</option>';
                                    }
                                    ?>
                                </select>
                            </div>

                            <label class="col-sm-2 control-label" for="subs_status">Contract Status</label>
                            <div class="col-sm-4">
                                <select name="subs_status" id="subs_status" class="form-control" required>
                                    <?php
                                    $cStatus = $editItem['subs_status'];
                                    $allowedStatuses = ['active','cancelled','expired','pending'];
                                    foreach ($allowedStatuses as $st) {
                                        $sel = ($cStatus === $st) ? 'selected' : '';
                                        echo '<option value="'.e($st).'" '.$sel.'>'.ucfirst($st).'</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-2 control-label" for="is_renewal">Is Renewal</label>
                            <div class="col-sm-4">
                                <select name="is_renewal" id="is_renewal" class="form-control">
                                    <?php
                                    $isRen = $editItem['is_renewal'];
                                    foreach (['N'=>'No','Y'=>'Yes'] as $v=>$label) {
                                        $sel = ($isRen === $v) ? 'selected' : '';
                                        echo '<option value="'.e($v).'" '.$sel.'>'.e($label).'</option>';
                                    }
                                    ?>
                                </select>
                            </div>

                            <label class="col-sm-2 control-label" for="parent_contract_id">Parent Contract ID</label>
                            <div class="col-sm-4">
                                <input type="text" name="parent_contract_id" id="parent_contract_id"
                                       class="form-control"
                                       value="<?= e($editItem['parent_contract_id']) ?>"
                                       placeholder="Leave blank if none"/>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-2 control-label" for="amt_notes">Amount Notes</label>
                            <div class="col-sm-4">
                                <input type="text" name="amt_notes" id="amt_notes"
                                       class="form-control"
                                       value="<?= e($editItem['amt_notes']) ?>"
                                       placeholder="e.g. ₹4,990/year (trial 30 days)"/>
                            </div>

                            <label class="col-sm-2 control-label" for="receipt_status">Receipt Status</label>
                            <div class="col-sm-4">
                                <input type="text" name="receipt_status" id="receipt_status"
                                       class="form-control"
                                       value="<?= e($editItem['receipt_status']) ?>"
                                       placeholder="e.g. paid, unpaid, partial" required/>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="col-sm-2 control-label" for="invoice_notes">Invoice Notes</label>
                            <div class="col-sm-10">
                                <input type="text" name="invoice_notes" id="invoice_notes"
                                       class="form-control"
                                       value="<?= e($editItem['invoice_notes']) ?>"
                                       placeholder="e.g. INV-2025-0001 | Razorpay pay_xxx"/>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="col-sm-4"></div>
                            <div class="col-sm-4">
                                <button type="submit" class="btn btn-primary btn-block">
                                    Update Contract
                                </button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- New Subscription Modal -->
<div class="modal fade" id="newSubModal" tabindex="-1" role="dialog" aria-labelledby="newSubLabel">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post" id="newSubForm">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal"
                  aria-label="Close"><span aria-hidden="true">&times;</span></button>
          <h4 class="modal-title" id="newSubLabel">Add New Subscription Contract</h4>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"/>
          <input type="hidden" name="action" value="add_new_sub"/>

          <div class="form-group">
            <label for="newBizId">Business</label>
            <select name="biz_id" id="newBizId" class="form-control" required>
              <option value="">-- Select Business --</option>
              <?php foreach ($bizOptions as $bid => $bname): ?>
                <option value="<?= (int)$bid ?>"><?= e($bname) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="newCustId">Customer ID</label>
            <input type="number" name="cust_id" id="newCustId" class="form-control" required
                   placeholder="cust_id (int)"/>
          </div>

          <div class="form-group">
            <label for="newCustEmail">Customer Email</label>
            <input type="email" name="cust_email" id="newCustEmail" class="form-control" required
                   placeholder="customer@example.com"/>
          </div>

          <div class="form-group">
            <label for="newProdItem">Product / Plan Code</label>
            <select name="prod_item_name" id="newProdItem" class="form-control" required>
              <option value="">-- Select Product --</option>
              <option value="Bahi">Bahi Desktop</option>
              <option value="ecom">e-Commerce</option>
              <option value="godam">Godam</option>
            </select>
            <p class="help-block">If needed you can edit the code later in the contract form.</p>
          </div>

          <div class="form-group">
            <label for="newPlan">Plan</label>
            <select name="subs_plan" id="newPlan" class="form-control">
              <option value="trial">Trial</option>
              <option value="free">Free</option>
              <option value="paid" selected>Paid</option>
            </select>
          </div>

          <div class="form-group">
            <label>Subscription Period</label>
            <div class="form-inline">
              <div class="form-group">
                <label for="newSubsStart">Start:&nbsp;</label>
                <input type="date" name="subs_start_dt" id="newSubsStart" class="form-control input-sm" required>
              </div>
              &nbsp;&nbsp;
              <div class="form-group">
                <label for="newSubsEnd">End:&nbsp;</label>
                <input type="date" name="subs_end_dt" id="newSubsEnd" class="form-control input-sm" required>
              </div>
            </div>
            <p class="help-block">
              Default is today as start and one year later as end. You can adjust these.
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

          <div class="form-group">
            <label for="newReceiptStatus">Receipt Status</label>
            <input type="text" name="receipt_status" id="newReceiptStatus" class="form-control"
                   value="unpaid" required>
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

    function toYMDLocal(d) {
        var year  = d.getFullYear();
        var month = (d.getMonth() + 1).toString().padStart(2, '0');
        var day   = d.getDate().toString().padStart(2, '0');
        return year + '-' + month + '-' + day;
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

    function updateNewSubPeriodType() {
        var startStr = $('#newSubsStart').val();
        var endStr   = $('#newSubsEnd').val();
        var label    = detectPeriodType(startStr, endStr);
        $('#newSubPeriodType').text(label);
    }

    // Header "Add Subscription" button
    $('#btnOpenNewSub').on('click', function(e) {
        e.preventDefault();

        // Reset form
        $('#newBizId').val('');
        $('#newCustId').val('');
        $('#newCustEmail').val('');
        $('#newProdItem').val('');
        $('#newPlan').val('paid');
        $('#newProdSrl').val('');
        $('#newProdNotes').val('');
        $('#newAmtNotes').val('');
        $('#newInvoiceNotes').val('');
        $('#newReceiptStatus').val('unpaid');

        var today      = new Date();
        var todayStr   = toYMDLocal(today);
        var nextYearS  = new Date(today);
        nextYearS.setFullYear(today.getFullYear() + 1);
        var nextYearStr = toYMDLocal(nextYearS);

        $('#newSubsStart').val(todayStr);
        $('#newSubsEnd').val(nextYearStr);
        updateNewSubPeriodType();

        $('#newSubModal').modal('show');
    });

    $('#newSubsStart, #newSubsEnd').on('change', updateNewSubPeriodType);

    $('#newSubForm').on('submit', function(e) {
        var bizId  = $('#newBizId').val();
        var custId = $('#newCustId').val();
        var email  = $('#newCustEmail').val();
        var prod   = $('#newProdItem').val();
        var start  = $('#newSubsStart').val();
        var end    = $('#newSubsEnd').val();

        if (!bizId) {
            alert('Please select a business.');
            e.preventDefault();
            return;
        }
        if (!custId) {
            alert('Customer ID is required.');
            e.preventDefault();
            return;
        }
        if (!email) {
            alert('Customer email is required.');
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
