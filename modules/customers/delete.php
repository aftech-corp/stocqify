<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('sales');

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');
$csrf  = get('csrf');

if (!hash_equals(csrfToken(), $csrf)) {
    flash('error', 'Invalid request.'); redirect(url('customers'));
}

// Block deletion if customer has any sales or debt records
$chkSales = $db->prepare('SELECT COUNT(*) FROM sales WHERE customer_id=? AND business_id=?');
$chkSales->execute([$id, $bizId]);
if ((int)$chkSales->fetchColumn() > 0) {
    flash('error', 'Cannot delete — this customer has sales records. Deactivate them instead.');
    redirect(url('customers'));
}
$chkDebts = $db->prepare('SELECT COUNT(*) FROM debts WHERE customer_id=? AND business_id=?');
$chkDebts->execute([$id, $bizId]);
if ((int)$chkDebts->fetchColumn() > 0) {
    flash('error', 'Cannot delete — this customer has debt records. Deactivate them instead.');
    redirect(url('customers'));
}

// Soft delete
$stmt = $db->prepare('UPDATE customers SET is_active=0 WHERE id=? AND business_id=?');
$stmt->execute([$id, $bizId]);
auditLog('delete', 'customers', $id);
flash('success', 'Customer deleted.');
redirect(url('customers'));
