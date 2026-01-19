<?php
ob_start();
session_start();

include 'include/dbo.php';
include 'include/param.php';
include 'include/amount-in-words.php';
include 'include/share_token.php';

$debug = 0;
$dbh = new dbo();

try {
  $t = $_GET['t'] ?? '';
  $payload = verify_print_token($t);

  $biz_id = (int)$payload['biz_id'];
  $dc_id  = (int)$payload['dc_id'];

  // Header (scoped by biz_id)
  $h = $dbh->prepare("SELECT * FROM table_dc_header WHERE biz_id=? AND dc_id=? LIMIT 1");
  $h->execute([$biz_id, $dc_id]);
  $header = $h->fetch(PDO::FETCH_ASSOC);
  if (!$header) { http_response_code(404); die("Not found"); }

  // Details
  $d = $dbh->prepare("SELECT * FROM table_dc_details WHERE parent_dc_id=? ORDER BY item_srl_no");
  $d->execute([$dc_id]);
  $details = $d->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
  http_response_code(403);
  die("Invalid/expired link");
}

// Company info uses $biz_id
include "company-info.php";

// GST txn type (since you don’t store gst_txn_type in dc header)
$gst_txn_type = 'interstate';
if (!empty($header['bil_state']) && !empty($comp_state) && strcasecmp($header['bil_state'], $comp_state) === 0) {
  $gst_txn_type = 'local';
}

// Discount check (optional column)
$disc_stmt = $dbh->prepare("SELECT COUNT(*) FROM table_dc_details WHERE parent_dc_id=? AND (discount_amt > 0 OR discount_pct > 0)");
$disc_stmt->execute([$dc_id]);
$dc_discount_count = (int)$disc_stmt->fetchColumn();

// Dispatch/EWB presence (for section visibility)
$hasDispatch = (
  !empty($header['transport_mode']) ||
  !empty($header['vehicle_no']) ||
  !empty($header['transport_doc_no']) ||
  !empty($header['transport_doc_dt']) ||
  !empty($header['transporter_id']) ||
  !empty($header['transporter_name']) ||
  !empty($header['distance_km']) ||
  !empty($header['place_of_supply']) ||
  !empty($header['ewb_num']) ||
  !empty($header['ewb_dt'])
);

$dc_num = $header['dc_num'] ?? '';
$dc_dt  = $header['dc_dt'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="images/icon.png" />
<title>Print Delivery Challan</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

<style>

	/* Outer page frame */
	#dc { border: 2px solid #000; }
	#party, #item_det, #dc_footer, #dispatch_block, #dc_header { border: 0 !important; }

	/* Tables own borders */
	#dc table { border-collapse: collapse; width: 100%; }
	#dc table, #dc tr, #dc th, #dc td { border: 1px solid #000; }

	/* Bootstrap table: stop it from interfering */
	#dc .table { border: 0 !important; margin-bottom: 0 !important; }
	#dc .table > thead > tr > th,
	#dc .table > tbody > tr > td { border: 1px solid #000 !important; }

	/* IMPORTANT: stop bootstrap row from shifting content */
	#dc .row { margin-left: 0 !important; margin-right: 0 !important; }

	/* Print-safe page box */
	#dc_print{
	  width: 210mm;
	  margin: 10px auto;
	  background: #fff;
	  padding: 8mm;
	  box-sizing: border-box;
	}

	body { background-color:#f7ece6; }
	#buttons_div { margin-top: 20px; text-align:center; }

	@media print {
	  body { background: #fff !important; }
	  #buttons_div { display:none !important; }
	  @page { size: A4; margin: 10mm; }
	  #dc_print{ width: auto; margin: 0; padding: 0; }
	  html, body { width: 100%; }
	}

	/* Column sizing for item table */
	#item_det table { table-layout: fixed; width: 100%; }
	#item_det th, #item_det td { padding: 6px 6px; }

	#item_det th:nth-child(1), #item_det td:nth-child(1){
	  text-align: center;
	  padding-left: 4px;
	  padding-right: 4px;
	  white-space: nowrap;
	}

	#item_det th:nth-child(2), #item_det td:nth-child(2){
	  word-break: break-word;
	  white-space: normal;
	}

	#item_det th:nth-child(3), #item_det td:nth-child(3),
	#item_det th:nth-child(4), #item_det td:nth-child(4){
	  white-space: nowrap;
	}


	/* Totals section: NO grid lines at all */
	#tax_details table,
	#tax_details tr,
	#tax_details td,
	#tax_details th{
	  border: none !important;
	}

	/* Optional: keep spacing */
	#tax_details td{
	  padding: 10px 6px;
	}

</style>

<script>
function printDC(dcnum){
  document.title = "DC:" + dcnum;
  window.print();
}
</script>

</head>

<body style="background-color:#f7ece6">
<div id="dc_print">
  <div id="dc">
  <!-- Header -->
  <div id="dc_header">
    <table style="width: 100%;">
      <tr>
        <td style="width:15%;">
          <?php
            if (!empty($logo_img_loc)) echo "<img src='../$logo_img_loc' width='200px'>";
          ?>
        </td>
        <td style="text-align:center;">
          <h2 style="color:red;font-weight:bold;"><?php echo $comp_name; ?></h2>
          <?php echo $comp_add1; ?> &nbsp; <?php echo $comp_state."-".$comp_pincode; ?><br>
          Phone: <?php echo $comp_phone1; ?>
          <?php if (($comp_tax_reg_status ?? '')=="R") echo " GSTIN:".$comp_gstin; ?>
          <?php if (($enable_pharma ?? 'N')=="Y") echo "<br> Drug License Number:".$drug_lic_no; ?>
        </td>
      </tr>
    </table>

    <div align="center" style="margin-top:10px;">
      <b>DELIVERY CHALLAN</b>
    </div>

	<table style="width:100%; margin-top:6px;">
	  <tr>
		<td style="padding:5px;width:50%;"><b>Delivery Challan No: <?php echo htmlspecialchars($dc_num); ?></b></td>
		<td style="padding:5px; width:50%; text-align:right;"><b>Date:
		  <?php
			$date = $dc_dt ? date_create($dc_dt) : null;
			echo $date ? date_format($date,"d-m-Y") : "";
		  ?>
		</b></td>
	  </tr>
	</table>


    <?php if (!empty($header['ref_order_num'])): ?>
      <div class="row" style="padding:0 15px;">
        <b>Order No:</b> <?php echo htmlspecialchars($header['ref_order_num']); ?>
        <?php if (!empty($header['ref_order_dt'])): ?>
          &nbsp;&nbsp; <b>Order Date:</b> <?php echo htmlspecialchars($header['ref_order_dt']); ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>

  <!-- Party block -->
  <div id="party">
    <table style="width: 100%;">
      <tr>
        <td style="width:50%;padding-left:20px;padding-top:10px;padding-bottom:10px;">
          <b>Bill To:</b><br>
          Name: <?php echo htmlspecialchars($header['bil_party_name'] ?? ''); ?><br>
          Address: <?php echo htmlspecialchars($header['bil_address'] ?? ''); ?><br>
          State: <?php echo htmlspecialchars($header['bil_state'] ?? ''); ?>-<?php echo htmlspecialchars($header['bil_pincode'] ?? ''); ?><br>
          Contact: <?php echo htmlspecialchars($header['bil_phone'] ?? ''); ?><br>
          GSTIN: <?php echo htmlspecialchars($header['bil_gstin'] ?? ''); ?><br>
        </td>

        <td style="width:50%;padding-left:20px;padding-top:10px;padding-bottom:10px;">
          <?php if (($header['diff_shp_add'] ?? 'N') === 'Y'): ?>
            <b>Ship To:</b><br>
            Name: <?php echo htmlspecialchars($header['shp_party_name'] ?? ''); ?><br>
            Address: <?php echo htmlspecialchars($header['shp_address'] ?? ''); ?><br>
            State: <?php echo htmlspecialchars($header['shp_state'] ?? ''); ?>-<?php echo htmlspecialchars($header['shp_pincode'] ?? ''); ?><br>
            Contact: <?php echo htmlspecialchars($header['shp_phone'] ?? ''); ?><br>
            GSTIN: <?php echo htmlspecialchars($header['shp_gstin'] ?? ''); ?><br>
          <?php endif; ?>
        </td>
      </tr>
    </table>
  </div>

  <!-- Dispatch / EWB -->
  <?php if ($hasDispatch): ?>
  <div id="dispatch_block">
    <table style="width:100%;">
      <tr>
        <td style="padding:10px;">
          <b>Dispatch / E-Way Bill Details</b><br>

          <?php if (!empty($header['transport_mode'])): ?>
            <b>Transport Mode:</b> <?php echo htmlspecialchars($header['transport_mode']); ?>&nbsp;&nbsp;
          <?php endif; ?>
          <?php if (!empty($header['vehicle_no'])): ?>
            <b>Vehicle No:</b> <?php echo htmlspecialchars($header['vehicle_no']); ?>&nbsp;&nbsp;
          <?php endif; ?>
          <br>

          <?php if (!empty($header['transport_doc_no'])): ?>
            <b>Transport Doc No:</b> <?php echo htmlspecialchars($header['transport_doc_no']); ?>&nbsp;&nbsp;
          <?php endif; ?>
          <?php if (!empty($header['transport_doc_dt'])): ?>
            <b>Doc Date:</b> <?php echo htmlspecialchars($header['transport_doc_dt']); ?>&nbsp;&nbsp;
          <?php endif; ?>
          <br>

          <?php if (!empty($header['transporter_id'])): ?>
            <b>Transporter ID:</b> <?php echo htmlspecialchars($header['transporter_id']); ?>&nbsp;&nbsp;
          <?php endif; ?>
          <?php if (!empty($header['transporter_name'])): ?>
            <b>Transporter:</b> <?php echo htmlspecialchars($header['transporter_name']); ?>&nbsp;&nbsp;
          <?php endif; ?>
          <br>

          <?php if (!empty($header['distance_km'])): ?>
            <b>Distance (KM):</b> <?php echo htmlspecialchars($header['distance_km']); ?>&nbsp;&nbsp;
          <?php endif; ?>
          <?php if (!empty($header['place_of_supply'])): ?>
            <b>Place of Supply:</b> <?php echo htmlspecialchars($header['place_of_supply']); ?>&nbsp;&nbsp;
          <?php endif; ?>
          <br>

          <?php if (!empty($header['ewb_num'])): ?>
            <b>EWB No:</b> <?php echo htmlspecialchars($header['ewb_num']); ?>
            <?php if (!empty($header['ewb_dt'])): ?>
              &nbsp;&nbsp; <b>EWB Date:</b> <?php echo htmlspecialchars($header['ewb_dt']); ?>
            <?php endif; ?>
          <?php endif; ?>

        </td>
      </tr>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!empty($header['note'])): ?>
    <div style="padding:10px;">
      <b>Remark:</b> <?php echo htmlspecialchars($header['note']); ?>
    </div>
  <?php endif; ?>

  <br>

  <!-- Items -->
  <div id="item_det">
    <table class="table" style="width:100%;margin-bottom:0px;">
		<colgroup>
		  <col style="width: 48px;">      <!-- Sr No: tiny -->
		  <col style="width: auto;">      <!-- Item Name: takes remaining -->
		  <col style="width: 70px;">      <!-- HSN/SAC -->
		  <col style="width: 55px;">      <!-- UOM -->
		  <col style="width: 80px;">      <!-- Unit Price -->
		  <?php if ($dc_discount_count > 0) echo '<col style="width: 75px;">'; ?>  <!-- Discount -->
		  <col style="width: 70px;">      <!-- Qty -->
		  <col style="width: 95px;">      <!-- Taxable / Item Total -->
		</colgroup>

	
		<tr>
			<th>Sr No</th>
			<th>Item Name</th>
			<th style="text-align:center;">HSN/SAC</th>
			<th style="text-align:center;">UOM</th>
			<th style="text-align:right;padding-right:30px;">Unit Price</th>
			<?php if ($dc_discount_count > 0) echo '<th style="text-align:center;">Discount</th>'; ?>
			<th style="text-align:center;">Quantity</th>
			<th style="text-align:right;padding-right:30px;">Item Total</th>
		</tr>

	  <?php foreach ($details as $row): ?>
		<tr>
			  <td><?php echo (int)$row['item_srl_no']; ?></td>
			  <td>
				<?php
				  echo htmlspecialchars($row['item_name'] ?? '');
				  if (!empty($row['item_note'])) {
					echo "<div style='font-size:10px; line-height:1.2; margin-top:2px;margin-left:12px;'>" .
								nl2br(htmlspecialchars($row['item_note'])) .
					"</div>";
				}				  
				?>
			  </td>
			  <td style="text-align:center;"><?php echo htmlspecialchars($row['hsn_code'] ?? ''); ?></td>
			  <td style="text-align:center;"><?php echo htmlspecialchars($row['uom'] ?? ''); ?></td>
			  <td style="text-align:right;padding-right:30px;"><?php echo htmlspecialchars($row['price'] ?? ''); ?></td>

			  <?php if ($dc_discount_count > 0): ?>
				<td style="text-align:right;padding-right:30px;">
				  <?php
					$dm = strtoupper(trim((string)($row['discount_mode'] ?? '')));
					if ($dm === 'AMT') echo "AMT:" . htmlspecialchars($row['discount_amt'] ?? '0');
					if ($dm === 'PCT') echo "PCT:" . htmlspecialchars($row['discount_pct'] ?? '0');
				  ?>
				</td>
			  <?php endif; ?>

			  <td style="text-align:center;"><?php echo htmlspecialchars($row['qty'] ?? ''); ?></td>
			  <td style="text-align:right;padding-right:30px;"><?php echo htmlspecialchars($row['taxable_amt'] ?? ''); ?></td>
		</tr>
		  <?php endforeach; ?>
    </table>
  </div>

  <!-- Totals -->
  <div id="tax_details">
    <table>
      <tr>
        <td style="text-align:right;width:75%;"><b>Total</b></td>
        <td style="text-align:right;"><?php echo $header['total_amt']; ?></td>
      </tr>

      <?php if ($gst_txn_type === "local") { ?>
        <tr>
          <td style="text-align:right;width:75%;"><b>CGST</b></td>
          <td style="text-align:right;"><?php echo $header['cgst_amt']; ?></td>
        </tr>
        <tr>
          <td style="text-align:right;width:75%;"><b>SGST</b></td>
          <td style="text-align:right;"><?php echo $header['sgst_amt']; ?></td>
        </tr>
      <?php } else { ?>
        <tr>
          <td style="text-align:right;width:75%;"><b>IGST</b></td>
          <td style="text-align:right;"><?php echo $header['igst_amt']; ?></td>
        </tr>
      <?php } ?>

      <tr>
        <td style="text-align:right;width:75%;"><b>Grand Total</b></td>
        <td style="text-align:right;"><?php echo $header['net_amt']; ?></td>
      </tr>

      <tr>
        <td colspan="2" style="text-align:right;">
          <b>Amount in Words :</b>
          <?php echo convertNumberToWords($header['net_amt']); ?>
        </td>
      </tr>
    </table>
  </div>

  <!-- Footer -->
  <div id="dc_footer">
    <table class="table" style="width: 100%;margin-bottom:0px;">
      <tr>
        <td style="width:50%">&nbsp;</td>
        <td style="width:50%"><center>
          For : <?php echo $comp_name; ?>
          <br><br><br>
          Authorized Signatory
        </center></td>
      </tr>
    </table>
  </div>

  <div id="buttons_div">
    <div style="text-align:center; margin-top:3px;">
      <button class="btn-primary btn-lg" onclick="printDC('<?php echo htmlspecialchars($dc_num); ?>')">Print DC</button>
    </div>
  </div>
</div>
</div>
</body>
</html>

