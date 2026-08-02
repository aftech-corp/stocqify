<?php
require_once __DIR__ . '/../../app/includes/auth.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    verifyCsrf();
    $db  = getDB();
    $me  = currentUser();
    $ids = $_POST['ids'] ?? 'all';

    if ($ids === 'all') {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")
           ->execute([$me['id']]);
    } else {
        $idList = array_values(array_filter(array_map('intval', explode(',', $ids))));
        if ($idList) {
            $ph = implode(',', array_fill(0, count($idList), '?'));
            $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($ph)")
               ->execute(array_merge([$me['id']], $idList));
        }
    }

    $ucStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $ucStmt->execute([$me['id']]);
    echo json_encode(['ok' => true, 'unread_count' => (int)$ucStmt->fetchColumn()]);
} catch (\Exception $e) {
    echo json_encode(['ok' => false, 'unread_count' => 0]);
}
