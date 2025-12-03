<?php
session_start();

/*
File Name: user-reg.php
Purpose   : New Business User Registration + Send Password-Set Link
*/

include 'include/dbo.php';
include 'include/param-pos.php';
include 'include/log_security_event.php';   // for security audit (login/reset/etc.)

$debug = 0; // set 1 only for debugging (never in production)

// Instantiate PDO connection
$dbh = new dbo();

// ---------- CSRF token ----------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$successMsg = '';
$errorMsg   = '';
$showForm   = true;

// ---------- Helper: basic IP ----------
function getClientIP(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['t_bttn'])) {

    // --- CSRF check ---
    if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $errorMsg = "Invalid request. Please reload the page and try again.";
        $showForm = true;
    } else {

        // --- Collect & validate input ---
        $t_email  = trim($_POST['t_email']  ?? '');
        $t_email2 = trim($_POST['t_email2'] ?? '');
        $t_name   = trim($_POST['t_name']   ?? '');
        $t_mob1   = trim($_POST['t_mob1']   ?? '');
        $t_mob2   = trim($_POST['t_mob2']   ?? '');
        $t_addr   = trim($_POST['t_addr']   ?? '');
        $t_refer  = trim($_POST['t_refer']  ?? '');

        if (!filter_var($t_email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = "Please enter a valid email address.";
        } elseif ($t_email !== $t_email2) {
            $errorMsg = "Entered email IDs do not match.";
        } elseif ($t_name === '' || $t_mob1 === '' || $t_addr === '' || $t_refer === '') {
            $errorMsg = "All required fields must be filled.";
        } else {

            // --- Prepare insert into biz_admin_users ---
            $ip   = getClientIP();
            $dtm  = gmdate('Y-m-d H:i:s'); // UTC timestamp
            $stat = 'registered';

            try {
                $dbh->beginTransaction();

                $sql = "
                    INSERT INTO biz_admin_users
                        (admin_name, admin_email, admin_phone, admin_phone2,
                         admin_addr, created_dtm, created_src, created_ip,
                         status, referred_by)
                    VALUES
                        (:name, :email, :phone1, :phone2,
                         :addr, :created_dtm, :created_src, :created_ip,
                         :status, :referred_by)
                ";

                $stmt = $dbh->prepare($sql);
                $stmt->execute([
                    ':name'        => $t_name,
                    ':email'       => $t_email,
                    ':phone1'      => $t_mob1,
                    ':phone2'      => $t_mob2,
                    ':addr'        => $t_addr,
                    ':created_dtm' => $dtm,
                    ':created_src' => 'webapp',
                    ':created_ip'  => $ip,
                    ':status'      => $stat,
                    ':referred_by' => $t_refer,
                ]);

                $user_id = (int)$dbh->lastInsertId();

                // --- Generate a password-set token (no delete of old rows) ---
                $token      = bin2hex(random_bytes(32));            // raw token
                $token_hash = hash('sha256', $token);               // stored hash
                $expires_at = gmdate('Y-m-d H:i:s', time() + 3600); // 1hr from now (UTC)
                $created_at = gmdate('Y-m-d H:i:s');

                $tokSql = "
                    INSERT INTO biz_admin_password_resets
                        (email, token_hash, expires_at, created_at, ip_address)
                    VALUES
                        (:email, :token_hash, :expires_at, :created_at, :ip)
                ";
                $tokStmt = $dbh->prepare($tokSql);
                $tokStmt->execute([
                    ':email'      => $t_email,
                    ':token_hash' => $token_hash,
                    ':expires_at' => $expires_at,
                    ':created_at' => $created_at,
                    ':ip'         => $ip,
                ]);

                // --- Audit: password_set_request triggered by registration ---
                logSecurityEvent(
                    $dbh,
                    $user_id,
                    $t_email,
                    'password_set_request',
                    true,
                    'trigger=registration'
                );

                $dbh->commit();

                // --- Build password-set link (using token) ---
                $confirm_url = rtrim($APP_URL, '/') .
                               "/user-reg-set-password.php?token=" . urlencode($token);

                // --- Build email body ---
                $message  = '<html><body>';
                $message .= "Dear " . htmlspecialchars($t_name, ENT_QUOTES, 'UTF-8') . ",<br>";
                $message .= "Greetings from " . htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8') . " !!!!<br><br>";
                $message .= "Thanks for registering with us. Your user ID is your email ID ("
                         . htmlspecialchars($t_email, ENT_QUOTES, 'UTF-8') . ").<br>";
                $message .= "Next step is setting your password.<br><br>";
                $message .= 'Please click here to <a href="' .
                            htmlspecialchars($confirm_url, ENT_QUOTES, 'UTF-8') .
                            '">set your password</a>.<br><br>';
                $message .= "Thanks,<br>";
                $message .= "Team - " . htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8') . "<br>";
                $message .= '</body></html>';

                if ($debug) {
                    echo "<pre>DEBUG EMAIL BODY:\n" .
                         htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
                         "</pre>";
                }

                // --- Send emails ---
                $to      = $t_email;
                $subject = $APP_NAME . ": User Registration - Request to Set Password";

                $headers  = "MIME-Version: 1.0\r\n";
                $headers .= "Content-type: text/html; charset=UTF-8\r\n";
                $headers .= "From: " . $FROM_EMAIL . "\r\n";

                @mail($to, $subject, $message, $headers);
                @mail("vijayrastogi@yahoo.com", $subject, $message, $headers);
                @mail("info@sisoft.in", $subject, $message, $headers);

                $successMsg =
                    "User Registration request received successfully. " .
                    "Please check email (" . htmlspecialchars($t_email, ENT_QUOTES, 'UTF-8') .
                    ") for setting the password.";
                $showForm = false;

            } catch (PDOException $e) {
                if ($dbh->inTransaction()) {
                    $dbh->rollBack();
                }

                // Duplicate email (unique constraint on admin_email)
                if ($e->getCode() === '23000') {
                    $errorMsg = "This email address is already registered.";
                } else {
                    $error_msg = "DB ERROR in user-reg: " . $e->getMessage();
                    error_log($error_msg);

                    // Notify admin
                    $headers = "From: " . $FROM_EMAIL . "\r\n";
                    $subject = $APP_NAME . ": User Registration - Error";
                    @mail("vijayrastogi@yahoo.com", $subject, $error_msg, $headers);

                    $errorMsg = "There was an error processing your request. Support team will contact you soon.";
                    if ($debug) {
                        $errorMsg .= " (Debug: " . $e->getMessage() . ")";
                    }
                }
            } catch (Throwable $e) {
                if ($dbh->inTransaction()) {
                    $dbh->rollBack();
                }
                $error_msg = "GENERIC ERROR in user-reg: " . $e->getMessage();
                error_log($error_msg);

                $headers = "From: " . $FROM_EMAIL . "\r\n";
                $subject = $APP_NAME . ": User Registration - Error";
                @mail("vijayrastogi@yahoo.com", $subject, $error_msg, $headers);

                $errorMsg = "There was an error processing your request. Support team will contact you soon.";
                if ($debug) {
                    $errorMsg .= " (Debug: " . $e->getMessage() . ")";
                }
            }
        }
    }
}
?>
<html>
<head>
    <title>New User Request - <?php echo htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description"
          content="<?php echo htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8'); ?> - New User Request" />

    <link rel="stylesheet"
          href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <script
      src="https://ajax.googleapis.com/ajax/libs/jquery/3.1.1/jquery.min.js"></script>
    <script
      src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

    <style type="text/css">
        body { background:#E2DEDE; }
        .table { display: table; }
        .row   { border: 1px gray; display: table-row; }
        .column {
            display: table-cell;
            vertical-align:middle;
            border-right:#FFFFFF solid 1px;
            border-bottom:#FFFFFF solid 1px;
            padding: 10px;
            text-align:center;
        }
    </style>

    <script>
    $(document).ready(function(){
        $("#t_email").focusout(function(){
            var user_email   = $("#t_email").val();
            var msgbox       = $("#error");
            var emailPattern = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;

            if(!emailPattern.test(user_email)){
                msgbox.html('<font color="#cc0000">Please enter valid email ID.</font>');
                $("#t_email").focus();
                return false;
            }

            if(user_email.length > 4){
                $("#error").html('<img src="images/loader.gif" align="absmiddle">&nbsp;Checking availability...');
                $.ajax({
                    type: "POST",
                    url: "user-reg-email-check-ajax.php",
                    data: { remail: user_email },
                    success: function(msg){
                        console.log(msg);
                        if(msg.includes('OK')){
                            document.getElementById("error").style.display="block";
                            msgbox.html('<img src="images/available.png" align="absmiddle">');
                        }
                        else{
                            $("#t_email").removeClass("green");
                            document.getElementById("error").style.display="block";
                            msgbox.html(msg);
                            $("#t_email").focus();
                        }
                    }
                });
            } else {
                document.getElementById("error").style.display="block";
                $("#error").html('<font color="#cc0000">Please enter at least 5 characters.</font>');
                $("#t_email").focus();
            }
            return false;
        });

        $("#t_email2").focusout(function(){
            var user_email    = $("#t_email").val();
            var confirm_email = $("#t_email2").val();
            if (user_email !== confirm_email){
                alert("Entered email IDs do not match");
            }
        });
    });
    </script>
</head>

<body>
<div class="container-fluid" style="background:#5bc0de; color:#fff; padding:15px 0;">
    <div class="container">
        <div style="float:left; padding-top:5px;">
            <span class="dropdown" style="font-size:25px;">Business User Registration</span>
        </div>
    </div>
</div>

<div class="container">
    <div id="enrolled" class="col-lg-12">

        <?php if (!empty($errorMsg)): ?>
            <div class="alert alert-danger fade in">
                <a href="#" id="close_me" class="close" data-dismiss="alert" aria-label="close">&times;</a>
                <strong>Error!</strong>
                <?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($successMsg)): ?>
            <div class="alert alert-success fade in">
                <a href="#" id="close_me" class="close" data-dismiss="alert" aria-label="close">&times;</a>
                <strong>Success!</strong>
                <?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <a href="index.php">
                <button type="button" class="btn btn-info">Continue</button>
            </a>
        <?php endif; ?>

        <?php if ($showForm): ?>
            <center><h3>User Registration</h3></center><br>
            <div id="error"></div>

            <form class="form-horizontal" id="form" name="applyform" method="post" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token"
                       value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                <fieldset>

                    <div class="form-group">
                      <label class="col-md-3 control-label" for="t_email">Email ID:</label>
                      <div class="col-md-5">
                        <input id="t_email" name="t_email" class="form-control input-md" required type="email">
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="col-md-3 control-label" for="t_email2">Confirm Email ID:</label>
                      <div class="col-md-5">
                        <input id="t_email2" name="t_email2" class="form-control input-md" required type="email">
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="col-md-3 control-label" for="name">Name:</label>
                      <div class="col-md-5">
                        <input id="name" name="t_name"
                               maxlength="50"
                               placeholder="Enter Name"
                               class="form-control input-md"
                               required
                               type="text">
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="col-md-3 control-label" for="t_mob1">Mobile 1:</label>
                      <div class="col-md-5">
                        <input id="t_mob1" name="t_mob1"
                               maxlength="14"
                               class="form-control input-md"
                               required
                               type="text">
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="col-md-3 control-label" for="t_mob2">Mobile 2:</label>
                      <div class="col-md-5">
                        <input id="t_mob2" name="t_mob2"
                               maxlength="14"
                               class="form-control input-md"
                               type="text">
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="col-md-3 control-label" for="t_addr">Address:</label>
                      <div class="col-md-5">
                        <textarea id="t_addr" name="t_addr"
                                  class="form-control input-md"
                                  required></textarea>
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="col-md-3 control-label" for="t_refer">Referred By:</label>
                      <div class="col-md-5">
                        <textarea id="t_refer" name="t_refer"
                                  class="form-control input-md"
                                  required></textarea>
                      </div>
                    </div>

                    <div class="form-group">
                      <label class="col-md-3 control-label" for="bttn"></label>
                      <div class="col-md-5">
                        <input type="submit" id="bttn" name="t_bttn"
                               class="btn btn-primary"
                               value="Apply now">
                      </div>
                    </div>

                </fieldset>
            </form>
        <?php endif; ?>

    </div>
</div>

<script>
  const pasteBox = document.getElementById("t_email2");
  if (pasteBox) {
      pasteBox.onpaste = function(e) {
          e.preventDefault();
          return false;
      };
  }
</script>

</body>
</html>
