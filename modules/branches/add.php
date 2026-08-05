<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('users');

$db     = getDB();
$bizId  = currentBusinessId();
$errors = [];

// Load business users for the manager dropdown
$usersQ = $db->prepare("SELECT u.id, u.name, r.name AS role_name
    FROM users u JOIN roles r ON r.id=u.role_id
    WHERE u.business_id=? AND u.is_active=1
    ORDER BY u.name");
$usersQ->execute([$bizId]);
$bizUsers = $usersQ->fetchAll();

if (isPost()) {
    verifyCsrf();
    $name          = trim(post('name'));
    $code          = trim(post('code'));
    $address       = trim(post('address'));
    $phone         = trim(post('phone'));
    $email         = trim(post('email'));
    $managerUserId = (int)post('manager_user_id') ?: null;
    $notes         = trim(post('notes'));

    if (empty($name)) $errors[] = 'Branch name is required.';
    if ($code) {
        $codeChk = $db->prepare('SELECT id FROM branches WHERE business_id=? AND code=?');
        $codeChk->execute([$bizId, $code]);
        if ($codeChk->fetch()) $errors[] = 'A branch with this code already exists.';
    }
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    $managerName = null;
    if ($managerUserId) {
        $uChk = $db->prepare('SELECT name FROM users WHERE id=? AND business_id=? AND is_active=1');
        $uChk->execute([$managerUserId, $bizId]);
        $uRow = $uChk->fetch();
        if (!$uRow) { $errors[] = 'Invalid manager selected.'; $managerUserId = null; }
        else         { $managerName = $uRow['name']; }
    }

    // Plan quota check
    $__branchLimit = planLimitCheck($bizId, 'max_branches');
    if ($__branchLimit['reached']) {
        $__lim = $__branchLimit['limit'];
        $errors[] = "Your current plan allows a maximum of {$__lim} " . ($__lim === 1 ? 'branch' : 'branches') . " (you currently have {$__branchLimit['count']}). Please contact your admin to upgrade your plan.";
    }

    if (empty($errors)) {
        $db->prepare('INSERT INTO branches (business_id,name,code,address,phone,email,manager_user_id,manager_name,notes) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$bizId, $name, $code ?: null, $address ?: null, $phone ?: null, $email ?: null, $managerUserId, $managerName, $notes ?: null]);
        flash('success', "Branch \"{$name}\" created successfully.");
        redirect(url('branches'));
    }
}

$pageTitle = 'Add Branch';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div>
    <div class="flex items-center gap-3 mb-6">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Add Branch</h2>
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Branch Name *</label>
                    <input type="text" name="name" value="<?= h(post('name')) ?>" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                        placeholder="e.g. Main Branch, Freetown East, Aberdeen Market...">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Branch Code</label>
                    <input type="text" name="code" value="<?= h(post('code')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                        placeholder="e.g. BR01, FT-EAST...">
                    <p class="text-xs text-gray-400 mt-0.5">Optional short identifier (must be unique).</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Branch Manager</label>
                    <select name="manager_user_id" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">-- No manager assigned --</option>
                        <?php foreach ($bizUsers as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= (int)post('manager_user_id') === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= h($u['name']) ?> <span style="color:#888">(<?= h($u['role_name']) ?>)</span>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($bizUsers)): ?>
                    <p class="text-xs text-amber-600 mt-0.5"><i class="fa-solid fa-circle-info mr-1"></i>No other users registered yet. Add users first to assign a manager.</p>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <?php $__dial = countryDialCode(); ?>
                    <input type="text" name="phone" value="<?= h(post('phone')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                        placeholder="<?= $__dial ? h($__dial . ' xxx xxxx') : '+xxx xxx xxxx' ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" value="<?= h(post('email')) ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <textarea name="address" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"
                        placeholder="Branch physical address..."><?= h(post('address')) ?></textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                    <textarea name="notes" rows="2"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"
                        placeholder="Optional notes..."><?= h(post('notes')) ?></textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <i class="fa-solid fa-save mr-1"></i> Create Branch
                </button>
                <a href="index.php" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
