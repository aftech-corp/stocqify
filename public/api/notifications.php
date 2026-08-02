<?php
require_once __DIR__ . '/../../app/includes/auth.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $db    = getDB();
    $me    = currentUser();
    $since = (int)($_GET['since'] ?? 0);

    // Ensure table exists (idempotent)
    $db->exec("CREATE TABLE IF NOT EXISTS `notifications` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`    INT UNSIGNED NOT NULL,
        `type`       VARCHAR(30) NOT NULL DEFAULT 'info',
        `title`      VARCHAR(150) NOT NULL,
        `body`       TEXT DEFAULT NULL,
        `link`       VARCHAR(500) DEFAULT NULL,
        `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `user_read` (`user_id`,`is_read`),
        KEY `created`   (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // New notifications since last known ID
    $nStmt = $db->prepare("SELECT id, type, title, body, link, is_read, created_at
                           FROM notifications
                           WHERE user_id = ? AND id > ?
                           ORDER BY id ASC");
    $nStmt->execute([$me['id'], $since]);
    $newItems = $nStmt->fetchAll(PDO::FETCH_ASSOC);

    // Current unread count
    $ucStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $ucStmt->execute([$me['id']]);
    $unread = (int)$ucStmt->fetchColumn();

    echo json_encode(['ok' => true, 'new' => $newItems, 'unread_count' => $unread]);
} catch (\Exception $e) {
    echo json_encode(['ok' => false, 'new' => [], 'unread_count' => 0]);
}
