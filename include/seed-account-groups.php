<?php
/**
 * Seed/Upsert minimal account groups for a business.
 * - Requires a UNIQUE(biz_id, group_code) on account_group.
 * - Idempotent: safely re-runnable.
 *
 * "inserted"  = groups that did not exist before this call.
 * "updated"   = groups that already existed before this call
 *              (even if the data ends up unchanged).
 *
 * @return array [inserted => int, updated => int]
 */
function seed_account_groups(PDO $dbh, int $biz_id): array
{
    // Ensure unique constraint exists (idempotent; ignores error if already there)
    try {

    // Define all groups
	
	$rows = [
    // --- Core current assets / liabilities ---
    ['CUSTOMER','Customers (A/R)','ASSET','DR','BS_CURRENT_ASSETS','OPERATING',10,1],
    ['VENDOR','Vendors (A/P)','LIABILITY','CR','BS_CURRENT_LIAB','OPERATING',10,1],
    ['BANK_BAHI','Bank Accounts','ASSET','DR','BS_CURRENT_ASSETS','CASH_EQUIVALENT',20,1],
    ['CASH_BAHI','Cash-in-Hand (Bahi)','ASSET','DR','BS_CURRENT_ASSETS','CASH_EQUIVALENT',30,1],
    ['INVENTORY','Inventory','ASSET','DR','BS_CURRENT_ASSETS','OPERATING',35,1],
    ['PREPAID_EXPENSES','Prepaid Expenses','ASSET','DR','BS_CURRENT_ASSETS','OPERATING',37,1],
    ['INPUT_TAX','Input Tax Credit (ITC)','ASSET','DR','BS_CURRENT_ASSETS','OPERATING',40,1],
    ['RCM_ITC','Reverse Charge - ITC','ASSET','DR','BS_CURRENT_ASSETS','OPERATING',41,1],
    ['TDS_RECEIVABLE','TDS Receivable','ASSET','DR','BS_CURRENT_ASSETS','OPERATING',45,1],

    ['SHORT_TERM_BORROWINGS','Short Term Borrowings','LIABILITY','CR','BS_CURRENT_LIAB','FINANCING',15,1],
    ['OUTPUT_TAX','Duties & Taxes - Output','LIABILITY','CR','BS_CURRENT_LIAB','OPERATING',40,1],
    ['RCM_PAYABLE','Reverse Charge - Payable','LIABILITY','CR','BS_CURRENT_LIAB','OPERATING',41,1],
    ['TDS_TCS_PAYABLE','TDS/TCS Payable','LIABILITY','CR','BS_CURRENT_LIAB','OPERATING',45,1],
    ['ACCRUED_EXPENSES','Accrued Expenses','LIABILITY','CR','BS_CURRENT_LIAB','OPERATING',47,1],

    // --- Non-current assets / liabilities ---
    ['FIXED_ASSETS','Fixed Assets','ASSET','DR','BS_NON_CURRENT_ASSETS','INVESTING',50,1],
    ['ACCUM_DEPRECIATION','Accumulated Depreciation','ASSET','CR','BS_NON_CURRENT_ASSETS','NON_CASH',55,1],
    ['LONG_TERM_BORROWINGS','Long Term Borrowings','LIABILITY','CR','BS_NON_CURRENT_LIAB','FINANCING',20,1],

    // --- Equity ---
    ['EQUITY_ACCOUNT','Equity / Capital','EQUITY','CR','BS_EQUITY','FINANCING',10,1],
    ['RETAINED_EARNINGS','Retained Earnings','EQUITY','CR','BS_EQUITY','NONE',20,1],
    ['DRAWINGS','Drawings','EQUITY','DR','BS_EQUITY','FINANCING',30,1],

    // --- Revenue / income ---
    ['INCOME_DIRECT','Operating Income','INCOME','CR','PL_REVENUE','OPERATING',10,1],
    ['SALES_REVENUE','Sales Revenue','INCOME','CR','PL_REVENUE','OPERATING',10,1],
    ['SALES_RETURNS','Sales Returns','INCOME','CR','PL_REVENUE','OPERATING',20,1],
    ['SALES_DISCOUNTS','Sales Discounts','INCOME','CR','PL_REVENUE','OPERATING',30,1],
    ['INCOME_INDIRECT','Other Income','INCOME','CR','PL_OTHER_INCOME','OPERATING',10,1],

    // --- Purchases / expenses ---
    ['EXPENSE_DIRECT','Direct Expenses (COGS)','EXPENSE','DR','PL_COGS','OPERATING',10,1],
    ['PURCHASE_ACCOUNTS','Purchase Accounts','EXPENSE','DR','PL_COGS','OPERATING',15,1],
    ['PURCHASE_RETURNS','Purchase Returns','EXPENSE','CR','PL_COGS','OPERATING',25,1],
    ['PURCHASE_DISCOUNTS','Purchase Discounts','EXPENSE','CR','PL_COGS','OPERATING',35,1],
    ['EXPENSE_INDIRECT','Indirect Expenses','EXPENSE','DR','PL_EXPENSE','OPERATING',10,1],
	
	['DEPRECIATION_EXPENSE','Depreciation Expense','EXPENSE','DR','PL_EXPENSE','NON_CASH',20,1],
];
	

    // Pre-fetch which group_codes already exist for this biz_id
    $codes = array_column($rows, 0); // first element of each row is group_code

    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    $lookupParams       = array_merge([$biz_id], $codes);

    $existingCodes = [];
    $existingSql = "
        SELECT group_code
        FROM account_group
        WHERE biz_id = ?
          AND group_code IN ($placeholders)
    ";
    $stmtExisting = $dbh->prepare($existingSql);
    $stmtExisting->execute($lookupParams);

    while ($code = $stmtExisting->fetchColumn()) {
        if ($code !== false) {
            $existingCodes[$code] = true;
        }
    }

    // Separate INSERT and UPDATE statements for exact counts
	
	$insertSql = "
    INSERT INTO account_group
    (biz_id, group_code, group_name, nature, normal_side,
     report_section, cashflow_section, display_order, is_system, is_active, created_dtm, created_by)
    VALUES
    (:biz_id, :code, :name, :nature, :ns, :section, :cf_section, :ord, :sys, 1, NOW(), 'system')
	";
	
 
	 $updateSql = "
		UPDATE account_group
		SET
		  group_name       = :name,
		  nature           = :nature,
		  normal_side      = :ns,
		  report_section   = :section,
		  cashflow_section = :cf_section,
		  display_order    = :ord,
		  is_system        = :sys,
		  is_active        = 1,
		  updated_dtm      = NOW(),
		  updated_by       = 'system'
		WHERE biz_id = :biz_id
		  AND group_code = :code
	";

    $insStmt = $dbh->prepare($insertSql);
    $updStmt = $dbh->prepare($updateSql);

    $inserted = 0;
    $updated  = 0;

    $ownTxn = false;

    try {
        if (!$dbh->inTransaction()) {
            $dbh->beginTransaction();
            $ownTxn = true;
        }


		foreach ($rows as [$code,$name,$nature,$ns,$section,$cfSection,$ord,$sys]) {
			$bind = [
				':biz_id'     => $biz_id,
				':code'       => $code,
				':name'       => $name,
				':nature'     => $nature,
				':ns'         => $ns,
				':section'    => $section,
				':cf_section' => $cfSection,
				':ord'        => $ord,
				':sys'        => $sys,
			];

            if (isset($existingCodes[$code])) {
                // Definitively an "update" (row existed before this function call)
                $ok = $updStmt->execute($bind);
                if (!$ok) {
                    throw new \RuntimeException("Failed update for group_code={$code}");
                }
                $updated++;
            } else {
                // Definitively an "insert"
                $ok = $insStmt->execute($bind);
                if (!$ok) {
                    throw new \RuntimeException("Failed insert for group_code={$code}");
                }
                $inserted++;
            }
        }

        if ($ownTxn) {
            $dbh->commit();
        }
    } catch (\Throwable $e) {
        if ($ownTxn && $dbh->inTransaction()) {
            $dbh->rollBack();
        }
        throw $e;
    }

    return ['inserted' => $inserted, 'updated' => $updated];
}
?>