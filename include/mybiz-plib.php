<?php

function getCategoryName($dbh, int $cat_id): ?string
{
    if ($cat_id <= 0) {
        return null;
    }

    $sql = "SELECT bcat_name 
            FROM biz_category 
            WHERE bcat_id = :cat_id
            LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bindValue(':cat_id', $cat_id, PDO::PARAM_INT);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? $row['bcat_name'] : null;
}


/**
 * Get access_role for a given business and user email.
 *
 * @return string|null  e.g. 'owner', 'manager', 'staff', 'viewer' or null if no access row
 */
function getBizUserRole($dbh, int $biz_id, string $email): ?string
{
    if ($biz_id <= 0 || trim($email) === '') {
        return null;
    }

    $sql = "SELECT access_role
            FROM biz_estab_user_access
            WHERE biz_id = :biz_id
              AND user_email = :user_email
              AND status = 'active'
            LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bindValue(':biz_id', $biz_id, PDO::PARAM_INT);
    $stmt->bindValue(':user_email', $email, PDO::PARAM_STR);
    $stmt->execute();

    $role = $stmt->fetchColumn();

    return $role !== false ? $role : null;
}

?>