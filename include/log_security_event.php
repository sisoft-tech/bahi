<?php
// include/log_security_event.php.php

/*
File Name: log_security_event.php 
*/

function logSecurityEvent(
    PDO    $dbh,
    ?int   $adminId,
    string $adminEmail,
    string $eventType,
    bool   $successFlag,
    ?string $eventNotes = null
): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        $nowUtc = (new DateTime('now', new DateTimeZone('UTC')))
                    ->format('Y-m-d H:i:s');

        $sql = "
            INSERT INTO zuser_security_audit
                (admin_id, admin_email, event_type, success_flag,
                 event_notes, ip_address, user_agent, created_at_utc)
            VALUES
                (:admin_id, :admin_email, :event_type, :success_flag,
                 :event_notes, :ip_address, :user_agent, :created_at_utc)
        ";

        $stmt = $dbh->prepare($sql);
        $stmt->execute([
            ':admin_id'       => $adminId,
            ':admin_email'    => $adminEmail,
            ':event_type'     => $eventType,
            ':success_flag'   => $successFlag ? 1 : 0,
            ':event_notes'    => $eventNotes,
            ':ip_address'     => $ip,
            ':user_agent'     => $ua,
            ':created_at_utc' => $nowUtc,
        ]);
    } catch (Throwable $e) {
        // Do NOT interrupt login/password flows due to logging issues
        error_log("SECURITY-AUDIT-ERROR: " . $e->getMessage());
    }
}
