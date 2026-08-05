<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('sales');

$db    = getDB();
$bizId = currentBusinessId();
$errors = [];

if (isPost()) {
    verifyCsrf();
    $name     = post('name');
    $phone    = post('phone');
    $email    = post('email');
    $address  = post('address');
    $bizName  = post('business_name');
    $credit   = (float)post('credit_limit', 0);

    if (empty($name)) $errors[] = 'Customer name is required.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    if (empty($errors)) {
        $stmt = $db->prepare('INSERT INTO customers (business_id, name, phone, email, address, business_name, credit_limit, branch_id) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$bizId, $name, $phone ?: null, $email ?: null, $address ?: null, $bizName ?: null, $credit, currentBranchId()]);
        $id = $db->lastInsertId();
        auditLog('create', 'customers', $id, [], compact('name','phone','email'));
        flash('success', "Customer '{$name}' added successfully.");
        redirect(url('customers'));
    }
}

$pageTitle = 'Add Customer';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="max-w-2xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Add New Customer</h2>
    </div>

    <?php if ($errors): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
        <?php foreach ($errors as $e): ?>
        <p class="text-red-700 text-sm"><i class="fa-solid fa-circle-exclamation mr-1"></i><?= h($e) ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="<?= h(post('name')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                        placeholder="e.g. Mohamed Kamara" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                    <input type="tel" name="phone" value="<?= h(post('phone')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                        placeholder="+232 76 000 000">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                    <input type="email" name="email" value="<?= h(post('email')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                        placeholder="customer@email.com">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Business / Company Name</label>
                    <input type="text" name="business_name" value="<?= h(post('business_name')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                        placeholder="Optional">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Credit Limit (<?= currencySymbol() ?>)</label>
                    <input type="number" name="credit_limit" value="<?= h(post('credit_limit', '0')) ?>" min="0" step="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <textarea name="address" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"
                        placeholder="Street, City..."><?= h(post('address')) ?></textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <i class="fa-solid fa-save mr-1"></i> Save Customer
                </button>
                <a href="index.php" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
