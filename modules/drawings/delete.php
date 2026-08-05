<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');

verifyCsrf();

$stmt = $db->prepare('SELECT id, amount, description FROM drawings WHERE id=? AND business_id=?');
$stmt->execute([$id, $bizId]);
$drawing = $stmt->fetch();

if (!$drawing) {
    flash('error', 'Drawing not found.');
    redirect(url('drawings'));
}

$db->prepare('DELETE FROM drawings WHERE id=? AND business_id=?')->execute([$id, $bizId]);
auditLog('delete','drawings',$id,['amount'=>$drawing['amount'],'description'=>$drawing['description']],[]);
flash('success', 'Drawing deleted.');
redirect(url('drawings'));
