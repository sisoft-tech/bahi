<?php
ob_start();
session_start();

include 'include/dbo.php';
include 'include/mybiz-plib.php';
include 'include/session.php';
include 'include/param-pos.php';

checksession();

$dbh = new dbo() ;

$username_head = $_SESSION['login'] ?? '';
$debug = 0;

$if_login = $username_head;

$base_qry = "SELECT * 
             FROM biz_establishment 
             ORDER BY user_added, biz_name";

if ($debug) {
    echo $base_qry;
}

$stmt = $dbh->prepare($base_qry);
$stmt->execute();
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" 
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml">

<head>
    <title>Euphoria Bahi - All Business</title>

    <link rel="shortcut icon" type="image/icon" href="image/icon-main.png"/>

    <meta name="description" content="Business Classifieds/Listing for Local Business" />
    <meta name="keywords" content="Business Classifieds, Free Business Listing" />
    <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
    <meta http-equiv="PRAGMA" content="NO-CACHE" />
    <meta http-equiv="EXPIRES" content="0" />

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" />

    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />

    <style>
        .row a {
            text-decoration: none;
            font-size: 16px;
            font-weight: bold;
        }
    </style>
</head>

<body>

<?php include("biz-header.php"); ?>

<div class="container">

    <div class="row" style="margin-top:30px;">
        <div class="col-lg-8">
            <h3>All Businesses</h3>
        </div>
        <div class="col-lg-4"></div>
    </div>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>#</th>
                <th>Owner User</th>
                <th>Business ID</th>
                <th>Business Name</th>
                <th>Business Category</th>
                <th>Phone</th>
                <th>Email<br>Website</th>
                <th>Address</th>
                <th>Billing Desktop</th>
            </tr>
        </thead>

        <tbody>

        <?php
        $i = 1;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $biz_id = (int)$row['biz_id'];
            $bcat_id = (int)$row['bcat_id'];

            $bcat_name = getCategoryName($dbh, $bcat_id);

            $logo_img_loc = $row['biz_logo_image_loc'] ?? '';
        ?>

            <tr>
                <td><?php echo $i; ?></td>

                <td><?php echo htmlspecialchars($row['user_added'] ?? '', ENT_QUOTES, 'ISO-8859-1'); ?></td>

                <td><?php echo $biz_id; ?></td>

                <td>
                    <?php
                    if (!empty($logo_img_loc)) {
                        echo "<img src='" . htmlspecialchars($logo_img_loc, ENT_QUOTES, 'ISO-8859-1') . "' width='50px'> ";
                    }

                    echo htmlspecialchars($row['biz_name'] ?? '', ENT_QUOTES, 'ISO-8859-1');
                    ?>
                </td>

                <td><?php echo htmlspecialchars($bcat_name ?? '', ENT_QUOTES, 'ISO-8859-1'); ?></td>

                <td>
                    <?php
                    echo htmlspecialchars($row['biz_phone1'] ?? '', ENT_QUOTES, 'ISO-8859-1');
                    echo "<br>";
                    echo htmlspecialchars($row['biz_phone2'] ?? '', ENT_QUOTES, 'ISO-8859-1');
                    ?>
                </td>

                <td>
                    <?php
                    echo htmlspecialchars($row['biz_email'] ?? '', ENT_QUOTES, 'ISO-8859-1');
                    echo "<br>";
                    echo htmlspecialchars($row['biz_website'] ?? '', ENT_QUOTES, 'ISO-8859-1');
                    ?>
                </td>

                <td>
                    <?php
                    echo htmlspecialchars($row['biz_area'] ?? '', ENT_QUOTES, 'ISO-8859-1');
                    echo "<br>";
                    echo htmlspecialchars($row['biz_city'] ?? '', ENT_QUOTES, 'ISO-8859-1');
                    ?>
                </td>

                <td style="text-align:center;">
                    <form action="pos/pos-index.php" method="POST">
                        <input type="hidden" name="biz_id" value="<?php echo $biz_id; ?>" />
                        <input type="hidden" name="user_email" value="<?php echo htmlspecialchars($if_login, ENT_QUOTES, 'ISO-8859-1'); ?>" />

                        <button class="btn-floating btn-large" type="submit" name="OWNER_POS">
                            <span class="material-symbols-outlined">
                                point_of_sale
                            </span>
                        </button>
                    </form>
                </td>
            </tr>

        <?php
            $i++;
        }
        ?>

        </tbody>
    </table>

</div>

<div><?php //include "biz-footer.php"; ?></div>

</body>
</html>