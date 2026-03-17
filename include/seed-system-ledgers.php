<?php
function ensureLedgerUnique(PDO $dbh): void {
    try { $dbh->exec("ALTER TABLE account_ledger ADD UNIQUE KEY uq_biz_ledgername (biz_id, account_name)"); }
    catch (\Throwable $e) { /* already exists */ }
}

function upsertLedger(PDO $dbh, int $biz, string $group, string $name, string $user): int {
    ensureLedgerUnique($dbh);
    // Try fetch existing
    $sel = $dbh->prepare("SELECT account_id FROM account_ledger WHERE biz_id=:b AND account_name=:n LIMIT 1");
    $sel->execute([':b'=>$biz, ':n'=>$name]);
    $id = $sel->fetchColumn();
    if ($id !== false) {
        $upd = $dbh->prepare("UPDATE account_ledger SET ac_group_code=:g, is_active=1 WHERE account_id=:id");
        $upd->execute([':g'=>$group, ':id'=>$id]);
        return (int)$id;
    }
    $ins = $dbh->prepare("
        INSERT INTO account_ledger
        (biz_id, ac_group_code, account_name, phone_num, address, state, pincode, email, gstin, pan,
         contact_person_name, custom_fld1, created_dtm, created_by, is_system, is_active, deactivated_dtm)
        VALUES (:b,:g,:n,NULL,NULL,'',NULL,NULL,NULL,'',NULL,NULL,NOW(),:u,1,1,NULL)
    ");
    $ins->execute([':b'=>$biz, ':g'=>$group, ':n'=>$name, ':u'=>$user]);
    return (int)$dbh->lastInsertId();
}

/**
 * Creates/updates all required system ledgers (name-based, no mapping table).
 * Returns array: name => account_id
 */
function seed_system_ledgers_by_name(PDO $dbh, int $biz_id, string $created_by='system'): array {

    try {
        // group_code => [ledger names...]
        $spec = [
            'SALES_REVENUE'   => ['Sales Revenue'],
            'PURCHASE_ACCOUNTS' => ['Purchase Accounts'],			
            'OUTPUT_TAX'      => ['Output CGST','Output SGST','Output IGST','GST Payable (Control)'],
            'INPUT_TAX'       => ['Input CGST','Input SGST','Input IGST'],
            'RCM_PAYABLE'     => ['RCM Payable'],
            'RCM_ITC'         => ['RCM ITC'],
            'TDS_TCS_PAYABLE' => ['TDS/TCS Payable'],
            'TDS_RECEIVABLE'  => ['TDS Receivable'],
            // Use ROUNDING Difference in EXPENSE_INDIRECT
            'EXPENSE_INDIRECT'        => ['Rounding Difference']
        ];

        $out = [];
        foreach ($spec as $group => $names) {
            foreach ($names as $nm) {
                $out[$nm] = upsertLedger($dbh, $biz_id, $group, $nm, $created_by);
            }
        }

        return $out;

    } catch (Throwable $e) {
        throw $e;
    }
}
?>
