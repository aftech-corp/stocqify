<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id', 0);
$csrf  = get('csrf');

if (!$id || !hash_equals(csrfToken(), $csrf)) {
    flash('error', 'Invalid request.');
    redirect(url('income'));
}

$stmt = $db->prepare('SELECT id, title FROM income WHERE id=? AND business_id=?');
$stmt->execute([$id, $bizId]);
$income = $stmt->fetch();

if (!$income) {
    flash('error', 'Income record not found.');
    redirect(url('income'));
}

$db->prepare('DELETE FROM income WHERE id=? AND business_id=?')->execute([$id, $bizId]);
auditLog('delete', 'income', $id);
flash('success', "Income '{$income['title']}' deleted.");
redirect(url('income'));
