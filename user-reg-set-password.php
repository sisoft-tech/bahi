<?php
session_start();

/*
File Name: user-reg-set-password.php 
*/

include "include/dbo.php";
include "include/param-pos.php";
include "include/log_security_event.php"; // <--- audit logger

$dbh   = new dbo();
$debug = 0; // set 1 only for debugging (never in production)

// ---------- CSRF token ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$token      = $_GET['token'] ?? '';
$tokenValid = false;
$resetEmail = '';
$errorMsg   = '';
$successMsg = '';
$showForm   = false;

// ---------- Helper: fetch valid reset record ----------
function findValidResetByToken(PDO $dbh, string $token): ?array {
    if ($token === '') {
        return null;
    }
    $token_hash = hash('sha256', $token);

    // Use UTC for consistency with reset script
    $now = (new DateTime('now', new DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s');

    $sql = "
        SELECT *
        FROM biz_admin_password_resets
        WHERE token_hash = :token_hash
          AND expires_at >= :now
        LIMIT 1
    ";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([
        ':token_hash' => $token_hash,
        ':now'        => $now,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---------- POST: handle password set ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['t_bttn'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $errorMsg = "Invalid request. Please reload the page and try again.";
    } else {
        $postedToken = $_POST['reset_token'] ?? '';
        $pwd1        = $_POST['pwd1'] ?? '';
        $pwd2        = $_POST['pwd2'] ?? '';

        // Basic server-side validation
        if (strlen($pwd1) < 6) {
            $errorMsg = "Minimum password length is 6 characters.";
        } elseif ($pwd1 !== $pwd2) {
            $errorMsg = "Passwords do not match.";
        } else {
            // Re-validate token & expiry
            $resetRow = findValidResetByToken($dbh, $postedToken);
            if (!$resetRow) {
                $errorMsg = "This password reset link is invalid or has expired.";
            } else {
                $resetEmail = $resetRow['email'];

                try {
                    $dbh->beginTransaction();

                    // Hash password (make sure your login uses password_verify() in login code)
                    // $passwordHash = password_hash($pwd1, PASSWORD_DEFAULT);

                    // Update admin user
                    $sql = "
                        UPDATE biz_admin_users
                        SET admin_pwd = :pwd, status = 'active'
                        WHERE admin_email = :email
                        LIMIT 1
                    ";
                    $stmt = $dbh->prepare($sql);
                    $stmt->execute([
                        ':pwd'   => $pwd1, // $passwordHash
                        ':email' => $resetEmail
                    ]);

                    // Fetch admin_id for audit (if any)
                    $adminName = null;
                    $stmt2 = $dbh->prepare("
                        SELECT admin_name
                        FROM biz_admin_users
                        WHERE admin_email = :email
                        LIMIT 1
                    ");
                    $stmt2->execute([':email' => $resetEmail]);
                    $adminIdRow = $stmt2->fetch();
                    if ($adminIdRow && isset($adminIdRow['admin_name'])) {
                        $adminName = (int)$adminIdRow['admin_name'];
                    }

                    // Consume/delete the token
                    $del = $dbh->prepare("DELETE FROM biz_admin_password_resets WHERE id = :id");
                    $del->execute([':id' => $resetRow['id']]);

                    $dbh->commit();

                    // --- Audit: successful password change (audit-only design) ---
                    logSecurityEvent(
                        $dbh,
                        $adminName,
                        $resetEmail,
                        'password_change',
                        true,
                        'trigger=reset_link'
                    );

                    // Send confirmation email
                    $message  = '<html><body>';
                    $message .= "Dear User " . htmlspecialchars($resetEmail, ENT_QUOTES, 'UTF-8') . "<br><br>";
                    $message .= "Greetings from " . htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8') . "!!!!<br><br>";
                    $message .= "Your password has been successfully set for email: "
                              . htmlspecialchars($resetEmail, ENT_QUOTES, 'UTF-8') . "<br><br>";
                    $message .= "You can now log in here:<br>";
                    $message .= '<a href="' . htmlspecialchars($APP_URL, ENT_QUOTES, 'UTF-8') . '">'
                              . "<b>" . htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8') . "</b></a><br><br>";
                    $message .= "Thanks,<br>";
                    $message .= "Team " . htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8') . "<br>";
                    $message .= '</body></html>';

                    $to      = $resetEmail;
                    $subject = $APP_NAME . ": Password Set Confirmation";

                    $headers  = "MIME-Version: 1.0\r\n";
                    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
                    $headers .= "From: " . $FROM_EMAIL . "\r\n";

                    @mail($to, $subject, $message, $headers);
                    @mail("vijayrastogi@yahoo.com", $subject, $message, $headers);

                    $successMsg = "Your password has been set successfully.";
                } catch (Throwable $e) {
                    if ($dbh->inTransaction()) {
                        $dbh->rollBack();
                    }
                    error_log("SET-PASSWORD-ERROR: " . $e->getMessage());
                    $errorMsg = "An internal error occurred. Please try again.";
                    if ($debug) {
                        $errorMsg .= " (Debug: " . $e->getMessage() . ")";
                    }
                }
            }
        }
    }
} else {
    // ---------- GET: validate token and show form ----------
    $resetRow = findValidResetByToken($dbh, $token);
    if ($resetRow) {
        $tokenValid = true;
        $resetEmail = $resetRow['email'];
        $showForm   = true;
    } else {
        $errorMsg = "This password reset link is invalid or has expired.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Password Set - <?php echo htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description"
          content="<?php echo htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8'); ?> - Password Set" />

    <link rel="stylesheet"
          href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <script
      src="https://ajax.googleapis.com/ajax/libs/jquery/3.1.1/jquery.min.js"></script>
    <script
      src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

    <style type="text/css">
        body { background:#ffffff; }
    </style>

    <script>
    $(document).ready(function(){
        $("#pwd1, #pwd2").on('focusout', function(){
            var pwd1 = $("#pwd1").val();
            var pwd2 = $("#pwd2").val();
            var msgbox = $("#error");
            msgbox.html('');

            if (pwd1.length > 0 && pwd1.length < 6) {
                msgbox.html('<font color="#cc0000">Minimum password length 6 characters</font>');
                $("#pwd1").focus();
                return false;
            }
            if (pwd2.length > 0 && pwd2.length < 6) {
                msgbox.html('<font color="#cc0000">Minimum password length 6 characters</font>');
                $("#pwd2").focus();
                return false;
            }
            if (pwd1.length >= 6 && pwd2.length >= 6 && pwd1 !== pwd2) {
                msgbox.html('<font color="#cc0000">Passwords do not match</font>');
                return false;
            }
        });
    });
    </script>
</head>
<body>
<div class="container-fluid" style="background:#5bc0de; color:#fff; padding:15px 0;">
    <div class="container">
        <div style="float:left; padding-top:5px;">
            <span class="dropdown" style="font-size:25px;">Business User - Set Password</span>
        </div>
    </div>
</div>

<div class="container">
    <div id="enrolled" class="col-lg-12">
        <center><h3>Set Password</h3></center><br>

        <div id="error">
            <?php if (!empty($errorMsg)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($successMsg)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <a href="index.php" class="btn btn-info">Continue to Login</a>
        <?php elseif ($showForm && $tokenValid): ?>
            <p>
                Setting password for account:
                <b><?php echo htmlspecialchars($resetEmail, ENT_QUOTES, 'UTF-8'); ?></b>
            </p>
            <form class="form-horizontal" id="form" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token"
                       value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="reset_token"
                       value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                <fieldset>
                    <div class="form-group">
                      <label class="col-md-3 control-label" for="pwd1">Password:</label>
                      <div class="col-md-5">
                        <input type="password"
                               id="pwd1"
                               name="pwd1"
                               minlength="6"
                               placeholder="Enter Password"
                               class="form-control input-md"
                               required>
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="col-md-3 control-label" for="pwd2">Confirm Password:</label>
                      <div class="col-md-5">
                        <input type="password"
                               id="pwd2"
                               name="pwd2"
                               minlength="6"
                               placeholder="Enter Confirm Password"
                               class="form-control input-md"
                               required>
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="col-md-3 control-label" for="t_bttn"></label>
                      <div class="col-md-5">
                        <input type="submit"
                               id="t_bttn"
                               name="t_bttn"
                               class="btn btn-primary"
                               value="Set Password">
                      </div>
                    </div>
                </fieldset>
            </form>
        <?php else: ?>
            <?php if (empty($errorMsg)): ?>
                <div class="alert alert-danger">
                    Invalid or expired password reset link.
                </div>
            <?php endif; ?>
            <a href="index.php" class="btn btn-info">Go to Login</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
