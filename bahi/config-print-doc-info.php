<?php
include_once 'include/dbo.php';

if (!isset($dbh)) { $dbh = new dbo(); }

// EXPECTED:
// - $biz_id available before include
// - $doc_type available before include (example: "QUOTE", "SALES", "DELIVERY CHALLAN")

	$doc_type = strtoupper(trim((string)$doc_type));
	$doc_type = str_replace('_', ' ', $doc_type);
	$doc_type = preg_replace('/\s+/', ' ', $doc_type);

	/* Defaults */
	$show_tnc = 'N';
	$detail_tnc = '';


	$show_bank_ac = 'N';
	$bank_ac_id = 0;         // NEW: preferred id column
	$bank_ac_text = '';      // NEW: preferred text column (fallback)


	$show_payments = 'N';
	$show_sign = 'N';
	$sign_file_path = '';

	$use_price = 'M';       // M => MRP , S => Sales Price
	$show_po_details = 'N';
	$receiver_sign = 'N';
	$show_despatch_det = 'N';
	$sale_invoice_format = 1;

	/* Derived bank outputs (for printing) */
	$bank_details_mode = 'NONE';   // NONE | ID | TEXT
	$bank_account_id = 0;

	$bank_name = '';
	$bank_branch = '';
	$bank_ac_no = '';
	$bank_ifsc = '';
	$bank_holder = '';
	$bank_swift = '';

	$detail_bank_text = '';        // if bank_ac was text
	$bank_line_html = '';          // ready html for echo (escaped)
	$bank_line_text = '';          // plain text (optional)

  $qry = "SELECT * FROM config_print_doc WHERE biz_id = :biz_id AND doc_type = :doc_type LIMIT 1";
  $stmt = $dbh->prepare($qry);
  $stmt->execute([':biz_id' => $biz_id, ':doc_type' => $doc_type]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    $show_tnc = $row["show_tnc"];
    $detail_tnc = $row["tnc"];

	$show_bank_ac = (string)($row["show_bank_ac"] ?? 'N');
	$bank_ac_id   = (int)($row["bank_ac_id"] ?? 0);
	$bank_ac_text = trim((string)($row["bank_ac"] ?? ''));
	
    $show_payments = $row["show_payments"];
    $show_sign = $row["show_sign"];
    $sign_file_path = (string)($row["sign_file_path"] ?? '');

    $use_price = (string)($row["use_price"] ?? 'M');
    $show_po_details = (string)($row['show_po_details'] ?? 'N');
    $receiver_sign = (string)($row['receiver_sign'] ?? 'N');
    $show_despatch_det = (string)($row['show_despatch_det'] ?? 'N');
    $sale_invoice_format = (int)($row['sale_invoice_format'] ?? 1);
}


/*
Bank rule:
- If show_bank_ac=Y 
** Use bank_ac_id first
** Else use bank_ac text
*/

	if ($show_bank_ac === 'Y') {
	  // Priority 1: bank_ac_id
	  $candidate_id = (int)$bank_ac_id;

	  if ($candidate_id > 0) {

		$bst = $dbh->prepare("
		  SELECT * FROM account_bank_details
		  WHERE biz_id = :biz_id AND account_id = :account_id
		  LIMIT 1
		");
		$bst->execute([':biz_id' => $biz_id, ':account_id' => $candidate_id]);
		$brow = $bst->fetch(PDO::FETCH_ASSOC);

		if ($brow) {
		  $bank_details_mode = 'ID';
		  $bank_account_id = $candidate_id;

		  $bank_name   = (string)($brow['bank_name'] ?? '');
		  $bank_branch = (string)($brow['branch_add'] ?? '');
		  $bank_ac_no  = (string)($brow['ac_number'] ?? '');
		  $bank_ifsc   = (string)($brow['bank_ifsc_cd'] ?? '');
		  $bank_holder = (string)($brow['ac_holder_name'] ?? '');
		  $bank_swift  = (string)($brow['swift_bic_code'] ?? '');

		  $tmp = "Bank: " . htmlspecialchars($bank_name, ENT_QUOTES, 'UTF-8');
		  if ($bank_branch !== '') $tmp .= " | Branch: " . htmlspecialchars($bank_branch, ENT_QUOTES, 'UTF-8');
		  if ($bank_holder !== '')  $tmp .= "<br>A/c Holder Name :" . htmlspecialchars($bank_holder, ENT_QUOTES, 'UTF-8');		  
		  if ($bank_ac_no !== '')  $tmp .= "<br>A/c No: " . htmlspecialchars($bank_ac_no, ENT_QUOTES, 'UTF-8');
		  if ($bank_ifsc !== '')   $tmp .= " | IFSC: " . htmlspecialchars($bank_ifsc, ENT_QUOTES, 'UTF-8');
		  $bank_line_html = $tmp;

		  $tmp2 = "Bank: " . $bank_name;
		  if ($bank_branch !== '') $tmp2 .= " | Branch: " . $bank_branch;
  		  if ($bank_holder !== '') $tmp2 .= "\nA/c Holder Name: " . $bank_holder;
		  if ($bank_ac_no !== '')  $tmp2 .= "\nA/c No: " . $bank_ac_no;
		  if ($bank_ifsc !== '')   $tmp2 .= " | IFSC: " . $bank_ifsc;
		  $bank_line_text = $tmp2;

		} else {
		  // id set but not found, fallback to text
		  if ($bank_ac_text !== '') {
			$bank_details_mode = 'TEXT';
			$detail_bank_text = $bank_ac_text;
			$bank_line_html = nl2br(htmlspecialchars($detail_bank_text, ENT_QUOTES, 'UTF-8'));
			$bank_line_text = $detail_bank_text;
		  } else {
			$bank_details_mode = 'NONE';
		  }
		}

	  } else {
		// Priority 2: bank_ac free text
		if ($bank_ac_text !== '') {
		  $bank_details_mode = 'TEXT';
		  $detail_bank_text = $bank_ac_text;
		  $bank_line_html = nl2br(htmlspecialchars($detail_bank_text, ENT_QUOTES, 'UTF-8'));
		  $bank_line_text = $detail_bank_text;
		} else {
		  $bank_details_mode = 'NONE';
		}
	  }
}


/* Helper: map format to print program (example for SALES invoice formats) */
function print_doc_pgm(string $doc_type, int $fmt): string {
  $doc_type = strtoupper(trim($doc_type));

  if ($doc_type === 'SALES') {
    if ($fmt === 1) return "bill-share-view.php";
    if ($fmt === 2) return "bill-share-view2.php";
	if ($fmt == 3) return "bill-pos-print.php" ;

    return "bill-share-view.php";
  }

  // default (for other docs you can extend later)
  return "";
}
