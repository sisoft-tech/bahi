<?php
include_once 'include/dbo.php'; 
include_once 'include/param.php';

$dbo = new dbo();

$biz_id = (int)($_SESSION['biz_id'] ?? 0);

if ($biz_id <= 0) {
    throw new RuntimeException('Invalid or missing business ID in session.');
}

//echo "Biz ID:".$biz_id ;

// Prepare and execute query using PDO
$sel_qry = "SELECT * FROM biz_establishment WHERE biz_id = :biz_id";
$stmt = $dbo->prepare($sel_qry);
$stmt->bindParam(':biz_id', $biz_id, PDO::PARAM_INT);
$stmt->execute();
//$stmt->debugDumpParams() ;

$comp_row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($comp_row === false) {
    throw new RuntimeException("Business record not found for biz_id: {$biz_id}");
}


$comp_name = $comp_row['biz_name'];
$comp_add1 = $comp_row['biz_street'] . ', ' . $comp_row['biz_area'] . ', ' . $comp_row['biz_city'];
$comp_state = $comp_row['biz_state'];
$comp_pincode = $comp_row['biz_pin'];
$comp_country = $comp_row['biz_country'];

$comp_currency = $comp_row['biz_currency'];
$comp_phone1 = $comp_row['biz_phone1'];
$comp_email1 = $comp_row['biz_email'];
$logo_img_loc = $comp_row['biz_logo_image_loc'];

// GST
$comp_tax_reg_status = $comp_row['biz_tax_reg_status'];
$comp_gstin = $comp_row['biz_gstin'];

// Pharma - from Biz Settings ---Check POS
$enable_pharma = 'N';
$drug_lic_no = "";
?>