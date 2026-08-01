<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('inventory');
$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');
if (!hash_equals(csrfToken(), get('csrf'))) { flash('error','Invalid request.'); redirect(url('products')); }
$db->prepare('UPDATE products SET is_active=0 WHERE id=? AND business_id=?')->execute([$id,$bizId]);
auditLog('delete','products',$id);
flash('success','Product deleted.');
redirect(url('products'));
