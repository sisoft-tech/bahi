<?php
/**
 * Seed product_uom for a given business.
 *
 * @param PDO|object $dbh        PDO or wrapper exposing prepare/execute and (ideally) beginTransaction/commit/rollBack/inTransaction
 * @param int        $biz_id     Target business id
 * @param string     $created_by Audit user (default 'system')
 * @param string|null $dtm       Timestamp (defaults to getLocalDtm() or now)
 * @param bool       $dry        If true, validates & counts but does not write
 * @return array                 ['inserted'=>N,'updated'=>N,'skipped'=>N,'errors'=>[...]]
 */
function seed_product_uom($dbh, int $biz_id, string $created_by = 'system', ?string $dtm = null, bool $dry = false): array
{
    $seed = [
            'pcs'  => 'Pieces',
            'nos'  => 'Number',
            'kg'   => 'Kilogram',
            'gm'    => 'Gram',
            'mg'   => 'Milligram',
            'ltr'    => 'Litre',
            'ml'   => 'Millilitre',
            'box'  => 'Box',
            'doz'  => 'Dozen',
            'pkt'  => 'Packet'
        ];
    if ($dtm === null) {
        $dtm = date('Y-m-d H:i:s');
    }

    $out = ['inserted'=>0,'updated'=>0,'skipped'=>0,'errors'=>[]];

    // Start a transaction only if the caller hasn't already
    $canTx = method_exists($dbh, 'beginTransaction') && method_exists($dbh, 'commit') && method_exists($dbh, 'rollBack');
    $inTx  = method_exists($dbh, 'inTransaction') ? $dbh->inTransaction() : false;
    $weStarted = false;

    try {
        if ($canTx && !$inTx) { $dbh->beginTransaction(); $weStarted = true; }

        $upsert = $dbh->prepare("
            INSERT INTO product_uom (biz_id, uom_cd, uom_desc, created_dtm, created_by)
            VALUES (:biz_id, :uom_cd, :uom_desc, :dtm, :by)
            ON DUPLICATE KEY UPDATE uom_desc = VALUES(uom_desc)
        ");
        $existsStmt = $dbh->prepare("SELECT 1 FROM product_uom WHERE biz_id = ? AND uom_cd = ? LIMIT 1");

        foreach ($seed as $code => $desc) {
            $code = substr(trim((string)$code), 0, 8);
            $desc = substr(trim((string)$desc), 0, 32);

            if ($code === '' || $desc === '') { $out['skipped']++; continue; }
            if ($dry) { $out['skipped']++; continue; }

            $existsStmt->execute([$biz_id, $code]);
            $exists = (bool)$existsStmt->fetchColumn();

            $upsert->execute([
                ':biz_id'   => $biz_id,
                ':uom_cd'   => $code,
                ':uom_desc' => $desc,
                ':dtm'      => $dtm,
                ':by'       => $created_by,
            ]);

            $exists ? $out['updated']++ : $out['inserted']++;
        }

        if ($weStarted) {
            if ($dry) $dbh->rollBack();
            else      $dbh->commit();
        }

    } catch (Throwable $e) {
        $out['errors'][] = $e->getMessage();
        if ($weStarted) { try { $dbh->rollBack(); } catch (Throwable $e2) {} }
    }

    return $out;
}
