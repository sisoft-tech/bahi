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

  $biz_id   = (int)($payload['biz_id'] ?? 0);
  $quote_id = (int)($payload['dc_id'] ?? 0);   // token function is generic; uses key dc_id

  // Header
  $h = $dbh->prepare("SELECT * FROM quote_header WHERE biz_id=? AND quote_id=? LIMIT 1");
  $h->execute([$biz_id, $quote_id]);
  $header = $h->fetch(PDO::FETCH_ASSOC);
  if (!$header) { http_response_code(404); die("Not found"); }

  // Details
  $d = $dbh->prepare("SELECT * FROM quote_details 
  WHERE biz_id=? AND parent_quote_id=? 
    ORDER BY
  CASE
    WHEN item_type = 'CHARGE'    THEN 2
    WHEN item_type = 'ROUND_OFF' THEN 3
    ELSE 1
  END, quote_detail_id" ) ;
  $d->execute([$biz_id, $quote_id]);
  $details = $d->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
  http_response_code(403);
  die("Invalid/expired link");
}

// Company info uses $biz_id
include "company-info.php";

$doc_type = "QUOTE" ;

include "config-print-doc-info.php";

// GST txn type is stored in quote header
$gst_txn_type = strtolower(trim((string)($header['gst_txn_type'] ?? 'local')));
if ($gst_txn_type !== 'interstate') $gst_txn_type = 'local';

// Discount column visibility
$disc_stmt = $dbh->prepare("
  SELECT COUNT(*) 
  FROM quote_details
  WHERE biz_id=? AND parent_quote_id=? AND (discount_amt > 0 OR discount_pct > 0)
");
$disc_stmt->execute([$biz_id, $quote_id]);
$quote_discount_count = (int)$disc_stmt->fetchColumn();

// Totals computed from details (to avoid relying on header totals that do not exist)
$tot_stmt = $dbh->prepare("
  SELECT 
    item_type, 
    COALESCE(SUM(qty * price), 0)                           AS gross_amt,
    COALESCE(SUM(CASE 
      WHEN discount_mode='AMT' THEN (qty * discount_amt)
      WHEN discount_mode='PCT' THEN (qty)*(price*discount_pct/100)
      ELSE 0 END), 0)                                       AS discount_total,
    COALESCE(SUM(taxable_amt), 0)                           AS taxable_total,
    COALESCE(SUM(cgst_amt), 0)                              AS cgst_total,
    COALESCE(SUM(sgst_amt), 0)                              AS sgst_total,
    COALESCE(SUM(igst_amt), 0)                              AS igst_total,
    COALESCE(SUM(gst_amt), 0)                               AS gst_total,
    COALESCE(SUM(taxable_amt + gst_amt), 0)                 AS net_total
  FROM quote_details
  WHERE biz_id=? AND parent_quote_id=?
");
$tot_stmt->execute([$biz_id, $quote_id]);
$tot = $tot_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$quote_num = $header['quote_num'] ?? '';
$quote_dt  = $header['quote_dt'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<link rel="icon" type="image/png" href="images/icon.png" />
<title>Print Quote</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

<style>
  /* Outer page frame */
  #quote { border: 2px solid #000; }
  #party, #item_det, #quote_footer, #quote_header { border: 0 !important; }

  /* Tables own borders */
  #quote table { border-collapse: collapse; width: 100%; }
  #quote table, #quote tr, #quote th, #quote td { border: 1px solid #000; }

  /* Bootstrap table: stop it from interfering */
  #quote .table { border: 0 !important; margin-bottom: 0 !important; }
  #quote .table > thead > tr > th,
  #quote .table > tbody > tr > td { border: 1px solid #000 !important; }

  /* IMPORTANT: stop bootstrap row from shifting content */
  #quote .row { margin-left: 0 !important; margin-right: 0 !important; }

  /* Print-safe page box */
  #quote_print{
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
    #quote_print{ width: auto; margin: 0; padding: 0; }
    html, body { width: 100%; }
  }

  /* Column sizing for item table */
  #item_det table { table-layout: fixed; width: 100%; }
  #item_det th, #item_det td { padding: 4px 4px; }

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
  #tax_details td{ padding: 10px 6px; }
</style>

<script>
function printQuote(qnum){
  document.title = "QUOTE:" + qnum;
  window.print();
}
</script>

</head>

<body style="background-color:#f7ece6">
<div id="quote_print">
  <div id="quote">

  <!-- Header -->
  <div id="quote_header">
    <table style="width: 100%;">
      <tr>
        <td style="width:15%;">
          <?php if (!empty($logo_img_loc)) echo "<img src='../$logo_img_loc' width='200px'>"; ?>
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
    <table style="width: 100%;">	
	  <tr>
		  <td colspan="2" style="text-align:center;">
				<b>QUOTATION</b>
		  </td>
	  </tr>
      <tr>
        <td style="padding:2px;width:50%;"><b>Quote No: <?php echo htmlspecialchars($quote_num); ?></b></td>
        <td style="padding:2px; width:50%; text-align:right;"><b>Date:
          <?php
            $date = $quote_dt ? date_create($quote_dt) : null;
            echo $date ? date_format($date,"d-m-Y") : "";
          ?>
        </b></td>
      </tr>
      <tr>
        <td style="padding:2px;width:50%;"><!--<b>Status:</b> <?php echo htmlspecialchars($header['quote_status'] ?? ''); ?>--></td>
        <td style="padding:2px; width:50%; text-align:right;"><b>Valid Upto:
          <?php
            $vu = $header['valid_upto'] ?? '';
            $vud = $vu ? date_create($vu) : null;
            echo $vud ? date_format($vud,"d-m-Y") : "";
          ?>
        </b></td>
      </tr>
    </table>

  </div>

  <!-- Party block (NO ship to) -->
  <div id="party">
    <table style="width: 100%;">
      <tr>
        <td style="width:100%;padding-left:20px;padding-top:10px;padding-bottom:10px;">
          <b>Bill To:</b><br>
          Name: <?php echo htmlspecialchars($header['party_name'] ?? ''); ?><br>
          Address: <?php echo htmlspecialchars($header['party_address'] ?? ''); ?><br>
          State: <?php echo htmlspecialchars($header['party_state'] ?? ''); ?>-<?php echo htmlspecialchars($header['party_pincode'] ?? ''); ?><br>
          Contact: <?php echo htmlspecialchars($header['party_phone'] ?? ''); ?><br>
          GSTIN: <?php echo htmlspecialchars($header['party_gstin'] ?? ''); ?><br>
          Email: <?php echo htmlspecialchars($header['party_email'] ?? ''); ?><br>
          <b>GST Type:</b> <?php echo htmlspecialchars($gst_txn_type); ?>
        </td>
      </tr>
    </table>
  </div>

  <br>

  <!-- Items -->
  <div id="item_det">
    <table class="table" style="width:100%;margin-bottom:0px;">
      <colgroup>
        <col style="width: 40px;">      <!-- Sr No -->
        <col style="width: auto;">      <!-- Item Name -->
        <col style="width: 60px;">      <!-- HSN -->
        <col style="width: 60px;">      <!-- GST %-->		
        <col style="width: 60px;">      <!-- UOM -->
        <col style="width: auto;">      <!-- Qty -->
        <col style="width: auto;">      <!-- Unit Price -->
        <?php if ($quote_discount_count > 0) echo '<col style="width: 75px;">'; ?>  <!-- Discount -->
        <col style="width: auto;">      <!-- Taxable -->

        <col style="width: auto;">     <!-- Line Total -->
      </colgroup>

      <tr>
        <th>SNo</th>
        <th>Item Name</th>
        <th style="text-align:center;">HSN/<br>SAC</th>
        <th style="text-align:right;padding-right:3px;">GST %</th>		
        <th style="text-align:center;">UOM</th>
        <th style="text-align:center;">Qty</th>
        <th style="text-align:right;padding-right:3px;">Unit Price</th>
        <?php if ($quote_discount_count > 0) echo '<th style="text-align:center;">Discount</th>'; ?>
        <th style="text-align:right;padding-right:3px;">Taxable</th>
        <th style="text-align:right;padding-right:3px;">Line Total</th>
      </tr>

      <?php $sr = 1; foreach ($details as $row): ?>
      <tr>
        <td><?php echo $sr++; ?></td>
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
        <td style="text-align:right;padding-right:3px;"><?php echo htmlspecialchars($row['hsn_code'] ?? ''); ?></td>
        <td style="text-align:right;padding-right:3px;"><?php echo htmlspecialchars($row['gst_pct'] ?? ''); ?></td>
        <td style="text-align:center;"><?php echo htmlspecialchars($row['uom'] ?? ''); ?></td>
        <td style="text-align:right;padding-right:3px;"><?php echo htmlspecialchars($row['qty'] ?? ''); ?></td>
        <td style="text-align:right;padding-right:3px;"><?php echo htmlspecialchars($row['price'] ?? ''); ?></td>

        <?php if ($quote_discount_count > 0): ?>
          <td style="text-align:right;padding-right:3px;">
            <?php
              $dm = strtoupper(trim((string)($row['discount_mode'] ?? '')));
              if ($dm === 'AMT') echo "AMT:" . htmlspecialchars($row['discount_amt'] ?? '0');
              if ($dm === 'PCT') echo "PCT:" . htmlspecialchars($row['discount_pct'] ?? '0');
              if ($dm !== 'AMT' && $dm !== 'PCT') echo "-";
            ?>
          </td>
        <?php endif; ?>

        <td style="text-align:right;padding-right:3px;"><?php echo htmlspecialchars($row['taxable_amt'] ?? ''); ?></td>
        <td style="text-align:right;padding-right:3px;">
          <?php
            $taxable = (float)($row['taxable_amt'] ?? 0);
            $gst     = (float)($row['gst_amt'] ?? 0);
            echo htmlspecialchars(number_format($taxable + $gst, 2));
          ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <!-- Totals -->
  <div id="tax_details">
    <table>
      <tr>
        <td style="text-align:right;width:75%;"><b>Taxable Total</b></td>
        <td style="text-align:right;"><?php echo htmlspecialchars(number_format((float)($tot['taxable_total'] ?? 0), 2)); ?></td>
      </tr>

      <?php if ($gst_txn_type === "local") { ?>
        <tr>
          <td style="text-align:right;width:75%;"><b>CGST</b></td>
          <td style="text-align:right;"><?php echo htmlspecialchars(number_format((float)($tot['cgst_total'] ?? 0), 2)); ?></td>
        </tr>
        <tr>
          <td style="text-align:right;width:75%;"><b>SGST</b></td>
          <td style="text-align:right;"><?php echo htmlspecialchars(number_format((float)($tot['sgst_total'] ?? 0), 2)); ?></td>
        </tr>
      <?php } else { ?>
        <tr>
          <td style="text-align:right;width:75%;"><b>IGST</b></td>
          <td style="text-align:right;"><?php echo htmlspecialchars(number_format((float)($tot['igst_total'] ?? 0), 2)); ?></td>
        </tr>
      <?php } ?>

      <tr>
        <td style="text-align:right;width:75%;"><b>Grand Total</b></td>
        <td style="text-align:right;"><?php echo htmlspecialchars(number_format((float)($tot['net_total'] ?? 0), 2)); ?></td>
      </tr>

      <tr>
        <td colspan="2" style="text-align:right;">
          <b>Amount in Words :</b>
          <?php echo convertNumberToWords((float)($tot['net_total'] ?? 0)); ?>
        </td>
      </tr>
    </table>
  </div>

  <!-- Footer (Bank details inline with signature) -->
  <div id="quote_footer">
    <table class="table" style="width: 100%;margin-bottom:0px;">
      <tr>
        <td style="width:50%; vertical-align:top;">
		
		<?php if (($show_bank_ac ?? 'N') === 'Y' && !empty($bank_line_html)): ?>
			<div style="font-size:11px; margin-top:6px; line-height:1.3;">
				<?php echo $bank_line_html; ?>
			</div>
		<?php endif; ?>
		
        </td>
        <td style="width:50%; vertical-align:top;">
          <center>
            For : <?php echo $comp_name; ?>
            <br><br><br>
            Authorized Signatory
          </center>
        </td>
      </tr>
    </table>
  </div>

  <div id="buttons_div">
    <div style="text-align:center; margin-top:3px;">
      <button class="btn-primary btn-lg" onclick="printQuote('<?php echo htmlspecialchars($quote_num); ?>')">Print Quote</button>
    </div>
  </div>

</div>
</div>
</body>
</html>
