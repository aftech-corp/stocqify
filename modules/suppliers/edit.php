<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('expenses');

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');
$errors = [];

$stmt = $db->prepare('SELECT * FROM suppliers WHERE id=? AND business_id=? AND is_active=1');
$stmt->execute([$id, $bizId]);
$supplier = $stmt->fetch();
if (!$supplier) { flash('error', 'Supplier not found.'); redirect(url('suppliers')); }

if (isPost()) {
    verifyCsrf();
    $name    = post('name');
    $phone   = post('phone');
    $email   = post('email');
    $address = post('address');
    $contact = post('contact_person');
    $opening = (float)post('opening_balance', 0);
    $notes   = post('notes');

    if (empty($name)) $errors[] = 'Supplier name is required.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if ($opening < 0) $errors[] = 'Opening balance cannot be negative.';

    if (empty($errors)) {
        $old = ['name' => $supplier['name'], 'opening_balance' => $supplier['opening_balance']];
        $db->prepare('UPDATE suppliers SET name=?,phone=?,email=?,address=?,contact_person=?,opening_balance=?,notes=?,updated_at=NOW() WHERE id=? AND business_id=?')
           ->execute([$name, $phone?:null, $email?:null, $address?:null, $contact?:null, $opening, $notes?:null, $id, $bizId]);
        auditLog('update', 'suppliers', $id, $old, compact('name','opening'));
        flash('success', 'Supplier updated successfully.');
        redirect(url('suppliers'));
    }
} else {
    // Pre-fill from DB
    $_POST = $supplier;
}

$pageTitle = 'Edit Supplier';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div>
    <div class="flex items-center gap-3 mb-6">
        <a href="view.php?id=<?= $id ?>" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Edit Supplier</h2>
    </div>

    <?php if ($errors): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
        <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Supplier Name *</label>
                    <input type="text" name="name" value="<?= h(post('name')) ?>" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="text" name="phone" value="<?= h(post('phone')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" value="<?= h(post('email')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contact Person</label>
                    <input type="text" name="contact_person" value="<?= h(post('contact_person')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Opening Balance (<?= currencySymbol() ?>)</label>
                    <input type="number" name="opening_balance" value="<?= h(post('opening_balance','0')) ?>" min="0" step="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <textarea name="address" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none resize-none"><?= h(post('address')) ?></textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none resize-none"><?= h(post('notes')) ?></textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <i class="fa-solid fa-save mr-1"></i> Update Supplier
                </button>
                <a href="view.php?id=<?= $id ?>" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
