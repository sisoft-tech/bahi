<?php
session_start();
require 'include/dbo.php';
require 'include/session.php';
require 'include/seed-account-groups.php';
require 'include/seed-system-ledgers.php';
require 'include/seed-product-uom.php';

checksession();

$dbh = new dbo(); // PDO
$biz_id = (int)($_GET['biz_id'] ?? ($_SESSION['biz_id'] ?? 0));
if ($biz_id <= 0) { http_response_code(400); echo 'Missing or invalid biz_id.'; exit; }

$user = $_SESSION['pos_login'] ?? ($_SESSION['login'] ?? 'system');

// helpers
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$hadError = false;

/**
 * Run a seeder, echo screen output, log errors.
 */
function run_seed(string $title, callable $fn, int $biz_id, string $section) {
    global $hadError, $h;
    echo '<h4 style="margin-top:20px;">'.$h($title)."</h4>\n";
    echo '<div class="panel panel-default"><div class="panel-body">';
    try {
        $res = $fn(); // call seeder
        echo '<pre style="white-space:pre-wrap;">'.$h(print_r($res, true))."</pre>";
    } catch (Throwable $e) {
        $hadError = true;
        error_log(sprintf(
            'SEED ERROR [%s] biz_id=%d: %s at %s:%d'."\n%s",
            $section, $biz_id, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
        ));
        echo '<div class="alert alert-danger"><strong>Error:</strong> '.$h($e->getMessage()).'</div>';
    }
    echo "</div></div>\n";
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Business Setup (Seeding)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet"
        href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <style>
    body { padding:20px; }
    .summary { margin-top: 10px; }
  </style>
</head>
<body>
  <div class="container-fluid">
    <h3>Initializing Business #<?= (int)$biz_id ?></h3>
    <p class="text-muted">Running initial data seeders…</p>

    <?php
      // Run seeders with on-screen output
      run_seed(
        'Seeding Account Groups',
        function() use ($dbh, $biz_id) { return seed_account_groups($dbh, $biz_id); },
        $biz_id,
        'account_groups'
      );

      run_seed(
        'Seeding System Ledgers',
        function() use ($dbh, $biz_id, $user) { return seed_system_ledgers_by_name($dbh, $biz_id, $user); },
        $biz_id,
        'system_ledgers'
      );

      run_seed(
        'Seeding Product Units of Measure',
        function() use ($dbh, $biz_id, $user) { return seed_product_uom($dbh, $biz_id, $user, null, false); },
        $biz_id,
        'product_uom'
      );
    ?>

    <div class="summary">
      <?php if ($hadError): ?>
        <div class="alert alert-warning">
          <strong>Business Setup completed with some issues.</strong>
          Please review the messages above; details are also logged to the server error log.
        </div>
      <?php else: ?>
        <div class="alert alert-success">
          <strong>Business Setup Done</strong>
        </div>
      <?php endif; ?>

      <button type="button" class="btn btn-primary"
              onclick="window.location.href='biz-mybusiness-manage.php'">
        Go to Next Step
      </button>
    </div>
  </div>
</body>
</html>
