<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');
$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');
if (!hash_equals(csrfToken(), get('csrf'))) { flash('error','Invalid request.'); redirect(url('expenses')); }
$db->prepare('DELETE FROM expenses WHERE id=? AND business_id=?')->execute([$id,$bizId]);
flash('success','Expense deleted.');
redirect(url('expenses'));
