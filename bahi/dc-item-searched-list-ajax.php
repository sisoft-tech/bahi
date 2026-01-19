<?php
declare(strict_types=1);

require_once 'include/dbo.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $biz_id = (int)($_POST['biz_id'] ?? 0);
    $q      = trim((string)($_POST['q'] ?? ''));

    if ($biz_id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Missing/invalid biz_id']);
        exit;
    }

    $pdo = new dbo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $like = ($q === '') ? '%' : ('%' . $q . '%');

    // Returns items for biz_id, optionally filtered by q (name/disp/desc/id)
	$sql = "
		SELECT
			pi.item_id,
			pi.item_type,
			pi.item_name,
			pi.item_disp_name,
			pi.item_desc,
			pi.item_uom,
			pi.item_pur_price,
			pi.avail_qty,
			pi.item_grp_id,

			COALESCE(pi.tracking_mode, 'NONE') AS tracking_mode,
			COALESCE(pi.expiry_required, 'N')  AS expiry_required,

			pg.grp_name,
			pg.hsn_code,
			pg.gst
		FROM product_item pi
		LEFT JOIN product_group pg
			   ON pg.biz_id = pi.biz_id
			   AND pg.grp_id = pi.item_grp_id

		WHERE pi.biz_id = ?
		  AND (
				? = ''
			 OR pi.item_name      LIKE ?
			 OR pi.item_disp_name LIKE ?
			 OR pi.item_desc      LIKE ?
			 OR CAST(pi.item_id AS CHAR) LIKE ?
		  )
		ORDER BY pi.item_name ASC
		LIMIT 100
	";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$biz_id, $q, $like, $like, $like, $like]);

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("purchase-get-item-data-ajax(PDO): " . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Server error']);
}
