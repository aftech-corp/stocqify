<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('sales');

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');
$errors = [];

$stmt = $db->prepare('SELECT * FROM customers WHERE id = ? AND business_id = ?');
$stmt->execute([$id, $bizId]);
$customer = $stmt->fetch();
if (!$customer) { flash('error', 'Customer not found.'); redirect(url('customers')); }

if (isPost()) {
    verifyCsrf();
    $old  = $customer;
    $name    = post('name');
    $phone   = post('phone');
    $email   = post('email');
    $address = post('address');
    $bizName = post('business_name');
    $credit  = (float)post('credit_limit', 0);

    if (empty($name)) $errors[] = 'Name is required.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';

    if (empty($errors)) {
        $stmt2 = $db->prepare('UPDATE customers SET name=?,phone=?,email=?,address=?,business_name=?,credit_limit=?,updated_at=NOW() WHERE id=? AND business_id=?');
        $stmt2->execute([$name,$phone?:null,$email?:null,$address?:null,$bizName?:null,$credit,$id,$bizId]);
        auditLog('update','customers',$id,$old,compact('name','phone','email','address'));
        flash('success','Customer updated successfully.');
        redirect(url('customers/view') . '?id=' . $id);
    }
}

// Pre-fill form
$f = isPost() ? $_POST : $customer;

$pageTitle = 'Edit Customer';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>
<div class="max-w-2xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="view.php?id=<?= $id ?>" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Edit Customer: <?= h($customer['name']) ?></h2>
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                    <input type="text" name="name" value="<?= h($f['name'] ?? '') ?>" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="tel" name="phone" value="<?= h($f['phone'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" value="<?= h($f['email'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Business Name</label>
                    <input type="text" name="business_name" value="<?= h($f['business_name'] ?? '') ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Credit Limit</label>
                    <input type="number" name="credit_limit" value="<?= h($f['credit_limit'] ?? '0') ?>" min="0" step="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <textarea name="address" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"><?= h($f['address'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <i class="fa-solid fa-save mr-1"></i> Update Customer
                </button>
                <a href="view.php?id=<?= $id ?>" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
