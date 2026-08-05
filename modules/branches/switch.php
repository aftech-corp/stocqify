<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();

if (!isPost()) { redirect(url('dashboard')); }
verifyCsrf();

$db    = getDB();
$bizId = currentBusinessId();
$reqId = (int)post('branch_id', 0);

if ($reqId > 0 && $bizId) {
    try {
        $chk = $db->prepare('SELECT id, name FROM branches WHERE id=? AND business_id=? AND is_active=1');
        $chk->execute([$reqId, $bizId]);
        $br = $chk->fetch();
        if ($br) {
            $_SESSION['active_branch_id']   = (int)$br['id'];
            $_SESSION['active_branch_name'] = $br['name'];
        }
    } catch (\Throwable $e) {
        // branches table may not exist yet
    }
} else {
    unset($_SESSION['active_branch_id'], $_SESSION['active_branch_name']);
}

// Redirect back to the referring page (it's a same-origin POST from the sidebar)
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref && strpos($ref, SITE_URL) === 0) {
    redirect($ref);
}
redirect(url('dashboard'));
