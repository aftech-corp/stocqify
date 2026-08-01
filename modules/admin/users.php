<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();
if (!isAdmin() && !hasPermission('users')) {
    http_response_code(403);
    include APP_PATH . '/includes/403.php';
    exit;
}

$db     = getDB();
$action = get('action', 'list');
$userId = (int)get('id', 0);
$errors = [];
$me     = currentUser();

// For non-admin users, all operations are scoped to their own business only
$ownerBizId = !isAdmin() ? (int)$me['business_id'] : null;

// Fetch roles — non-admins cannot see or assign the 'admin' system role
$rolesQ = isAdmin()
    ? $db->query("SELECT id, name, slug FROM roles ORDER BY name")
    : $db->query("SELECT id, name, slug FROM roles WHERE slug != 'admin' ORDER BY name");
$roles = $rolesQ->fetchAll();

// Fetch businesses for admin's dropdown only
$bizOptions = [];
if (isAdmin()) {
    $bizsQ = $db->query('SELECT id, name FROM businesses WHERE is_active=1 ORDER BY name');
    $bizOptions = $bizsQ->fetchAll();
}

// ── Ownership guard: ensure $userId belongs to the current owner's business ──
function assertOwnership(PDO $db, int $userId, ?int $ownerBizId): void {
    if ($ownerBizId === null) return; // admin: no restriction
    $chk = $db->prepare('SELECT id FROM users WHERE id=? AND business_id=?');
    $chk->execute([$userId, $ownerBizId]);
    if (!$chk->fetch()) {
        flash('error', 'Access denied.');
        redirect(url('admin/users'));
    }
}

// ==================== DELETE ====================
if ($action === 'delete' && $userId) {
    verifyCsrf();
    assertOwnership($db, $userId, $ownerBizId);
    if ($userId === (int)$me['id']) {
        flash('error', 'You cannot delete your own account.');
    } else {
        // Block deletion if user has recorded transactions
        $chk = $db->prepare('SELECT (SELECT COUNT(*) FROM sales WHERE user_id=?) + (SELECT COUNT(*) FROM payments WHERE user_id=?)');
        $chk->execute([$userId, $userId]);
        if ((int)$chk->fetchColumn() > 0) {
            flash('error', 'Cannot delete — this user has recorded transactions. Deactivate their account instead.');
            redirect(url('admin/users'));
        }
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
        auditLog('delete', 'users', $userId);
        flash('success', 'User deleted successfully.');
    }
    redirect(url('admin/users'));
}

// ==================== TOGGLE ACTIVE ====================
if ($action === 'toggle' && $userId) {
    verifyCsrf();
    assertOwnership($db, $userId, $ownerBizId);
    $stmt = $db->prepare('SELECT is_active FROM users WHERE id=?');
    $stmt->execute([$userId]);
    $current = (int)$stmt->fetchColumn();
    $db->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([$current ? 0 : 1, $userId]);
    flash('success', 'User status updated.');
    redirect(url('admin/users'));
}

// ==================== ADD / EDIT ====================
if (in_array($action, ['add', 'edit'])) {
    $user_data = ['name'=>'','email'=>'','phone'=>'','role_id'=>'','business_id'=>$ownerBizId ?? '','is_active'=>1];

    if ($action === 'edit' && $userId) {
        assertOwnership($db, $userId, $ownerBizId);
        $stmt = $db->prepare('SELECT * FROM users WHERE id=?');
        $stmt->execute([$userId]);
        $user_data = $stmt->fetch() ?: $user_data;
    }

    if (isPost()) {
        verifyCsrf();
        $name      = post('name');
        $email     = post('email');
        $phone     = post('phone');
        $roleId    = (int)post('role_id');
        // Non-admins are always locked to their own business
        $bizId     = $ownerBizId ?? ((int)post('business_id') ?: null);
        $isActive  = (int)post('is_active', 1);
        $password  = $_POST['password'] ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        if (empty($name))  $errors[] = 'Name is required.';
        if (empty($email)) $errors[] = 'Email is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
        if (empty($roleId)) $errors[] = 'Role is required.';

        // Non-admin: prevent assigning the system admin role via form manipulation
        if (!isAdmin()) {
            $roleCheck = $db->prepare("SELECT slug FROM roles WHERE id=?");
            $roleCheck->execute([$roleId]);
            if (($roleCheck->fetchColumn() ?: '') === 'admin') {
                $errors[] = 'You are not allowed to assign the Administrator role.';
            }
        }

        $dupStmt = $db->prepare('SELECT id FROM users WHERE email=? AND id!=?');
        $dupStmt->execute([$email, $userId]);
        if ($dupStmt->fetch()) $errors[] = 'Email already in use by another user.';

        if ($action === 'add' && empty($password)) $errors[] = 'Password is required.';
        if (!empty($password) && strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
        if (!empty($password) && $password !== $confirmPw) $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            if ($action === 'add') {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare('INSERT INTO users (business_id,role_id,name,email,phone,password,is_active) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([$bizId, $roleId, $name, $email, $phone?:null, $hash, $isActive]);
                auditLog('create', 'users', (int)$db->lastInsertId(), [], ['name'=>$name,'email'=>$email]);
                flash('success', "User '{$name}' created successfully.");
            } else {
                $updateSql = 'UPDATE users SET business_id=?,role_id=?,name=?,email=?,phone=?,is_active=? WHERE id=?';
                $params    = [$bizId, $roleId, $name, $email, $phone?:null, $isActive, $userId];
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $updateSql = 'UPDATE users SET business_id=?,role_id=?,name=?,email=?,phone=?,is_active=?,password=? WHERE id=?';
                    $params    = [$bizId, $roleId, $name, $email, $phone?:null, $isActive, $hash, $userId];
                }
                $db->prepare($updateSql)->execute($params);
                auditLog('update', 'users', $userId, [], ['name'=>$name,'email'=>$email]);
                flash('success', "User '{$name}' updated successfully.");
            }
            redirect(url('admin/users'));
        }

        $user_data = array_merge($user_data, compact('name','email','phone','roleId','bizId','isActive'));
    }

    // Render form
    $pageTitle = $action === 'add' ? 'Add User' : 'Edit User';
    include __DIR__ . '/../../app/includes/header.php';
    include __DIR__ . '/../../app/includes/sidebar.php';
    ?>
    <div class="max-w-2xl mx-auto">
        <div class="flex items-center gap-3 mb-6">
            <a href="users.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
            <h2 class="text-xl font-bold text-gray-800"><?= $pageTitle ?></h2>
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
                        <input type="text" name="name" value="<?= h($user_data['name']) ?>" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email Address *</label>
                        <input type="email" name="email" value="<?= h($user_data['email']) ?>" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                        <input type="text" name="phone" value="<?= h($user_data['phone'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none" placeholder="+232...">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Role *</label>
                        <select name="role_id" required class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                            <option value="">-- Select Role --</option>
                            <?php foreach ($roles as $r): ?>
                            <option value="<?= $r['id'] ?>" <?= ($user_data['role_id'] ?? '') == $r['id'] ? 'selected' : '' ?>><?= h($r['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (isAdmin()): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Business</label>
                        <select name="business_id" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                            <option value="">-- No Business (Admin Only) --</option>
                            <?php foreach ($bizOptions as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= ($user_data['business_id'] ?? '') == $b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="business_id" value="<?= $ownerBizId ?>">
                    <?php endif; ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Password <?= $action === 'edit' ? '(leave blank to keep current)' : ' *' ?></label>
                        <input type="password" name="password" autocomplete="new-password"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="<?= $action === 'edit' ? 'Leave blank to keep current' : 'Min. 6 characters' ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                        <input type="password" name="confirm_password" autocomplete="new-password"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="Repeat password">
                    </div>
                    <div class="md:col-span-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="is_active" value="1" <?= $user_data['is_active'] ? 'checked' : '' ?> class="w-4 h-4 rounded text-blue-600">
                            <span class="text-sm font-medium text-gray-700">Account Active</span>
                        </label>
                    </div>
                </div>
                <div class="flex gap-3 mt-6">
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                        <i class="fa-solid fa-save mr-1"></i> <?= $action === 'add' ? 'Create User' : 'Save Changes' ?>
                    </button>
                    <a href="users.php" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php
    include __DIR__ . '/../../app/includes/footer.php';
    exit;
}

// ==================== LIST VIEW ====================
$search    = get('search');
$roleFilter = (int)get('role');
$bizFilter  = (int)get('business');
$page       = max(1, (int)get('page', 1));

$where  = '1=1';
$params = [];

// System admin role is always hidden from non-admins
if (!isAdmin()) {
    $where .= " AND r.slug != 'admin'";
}

// Business owners see only their own business's users
if ($ownerBizId) {
    $where .= ' AND u.business_id = ?';
    $params[] = $ownerBizId;
}

if ($search) {
    $where .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $s = "%{$search}%";
    $params = array_merge($params, [$s, $s, $s]);
}
if ($roleFilter) { $where .= ' AND u.role_id=?'; $params[] = $roleFilter; }
// Business filter only applies for admin (owners are already scoped)
if (isAdmin() && $bizFilter) { $where .= ' AND u.business_id=?'; $params[] = $bizFilter; }

$totalQ = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE $where");
$totalQ->execute($params);
$total = (int)$totalQ->fetchColumn();
$pag   = paginate($total, $page);

$stmt = $db->prepare("SELECT u.*, r.name AS role_name, r.slug AS role_slug, b.name AS business_name
    FROM users u
    JOIN roles r ON r.id=u.role_id
    LEFT JOIN businesses b ON b.id=u.business_id
    WHERE $where
    ORDER BY u.created_at DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$users = $stmt->fetchAll();

// Stats scoped the same way
$statsWhere  = $where; // reuse the same conditions
$statsParams = $params;
$statsQ = $db->prepare("SELECT COUNT(*) AS total,
    SUM(u.is_active) AS active,
    SUM(CASE WHEN u.last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS active_7d
    FROM users u JOIN roles r ON r.id=u.role_id WHERE {$statsWhere}");
$statsQ->execute($statsParams);
$stats = $statsQ->fetch();

$pageTitle = 'User Management';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">User Management</h2>
        <p class="text-sm text-gray-500"><?= number_format($total) ?> user<?= $total != 1 ? 's' : '' ?></p>
    </div>
    <a href="users.php?action=add" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
        <i class="fa-solid fa-plus mr-1"></i> Add User
    </a>
</div>

<!-- Stats -->
<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-2xl font-black text-gray-800"><?= number_format($stats['total']) ?></p>
        <p class="text-sm text-gray-500">Total Users</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-2xl font-black text-green-600"><?= number_format($stats['active']) ?></p>
        <p class="text-sm text-gray-500">Active Accounts</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-2xl font-black text-blue-600"><?= number_format($stats['active_7d']) ?></p>
        <p class="text-sm text-gray-500">Active (7 Days)</p>
    </div>
</div>

<!-- Filter -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3">
        <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search name, email, phone..."
            class="flex-1 min-w-48 border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <select name="role" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Roles</option>
            <?php foreach ($roles as $r): ?>
            <option value="<?= $r['id'] ?>" <?= $roleFilter == $r['id'] ? 'selected' : '' ?>><?= h($r['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (isAdmin()): ?>
        <select name="business" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Businesses</option>
            <?php foreach ($bizOptions as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $bizFilter == $b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Filter</button>
        <a href="users.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Clear</a>
    </form>
</div>

<!-- Table -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">User</th>
                    <th class="px-4 py-3 font-medium text-left">Role</th>
                    <?php if (isAdmin()): ?>
                    <th class="px-4 py-3 font-medium text-left">Business</th>
                    <?php endif; ?>
                    <th class="px-4 py-3 font-medium text-left">Last Login</th>
                    <th class="px-4 py-3 font-medium text-center">Status</th>
                    <th class="px-4 py-3 font-medium text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($users)): ?>
                <tr><td colspan="<?= isAdmin() ? 6 : 5 ?>" class="px-4 py-8 text-center text-gray-400">No users found.</td></tr>
                <?php else: ?>
                <?php foreach ($users as $u): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-sm">
                                <?= strtoupper(substr($u['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <p class="font-medium text-gray-800"><?= h($u['name']) ?></p>
                                <p class="text-xs text-gray-400"><?= h($u['email']) ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <?php
                        $roleColors = ['admin'=>'purple','owner'=>'blue','manager'=>'indigo','sales_officer'=>'green','accountant'=>'orange'];
                        $rc = $roleColors[$u['role_slug']] ?? 'gray';
                        ?>
                        <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-<?= $rc ?>-100 text-<?= $rc ?>-800"><?= h($u['role_name']) ?></span>
                    </td>
                    <?php if (isAdmin()): ?>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?= h($u['business_name'] ?? 'System Admin') ?></td>
                    <?php endif; ?>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?= $u['last_login'] ? formatDateTime($u['last_login']) : 'Never' ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $u['is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                            <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex justify-center gap-1">
                            <a href="users.php?action=edit&id=<?= $u['id'] ?>" class="text-blue-600 p-1 hover:text-blue-800" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <a href="users.php?action=toggle&id=<?= $u['id'] ?>&csrf=<?= csrfToken() ?>"
                               class="<?= $u['is_active'] ? 'text-yellow-500 hover:text-yellow-700' : 'text-green-500 hover:text-green-700' ?> p-1"
                               title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>"
                               onclick="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> this user?')">
                                <i class="fa-solid fa-<?= $u['is_active'] ? 'user-slash' : 'user-check' ?>"></i>
                            </a>
                            <?php if ($u['id'] !== (int)$me['id']): ?>
                            <a href="users.php?action=delete&id=<?= $u['id'] ?>&csrf=<?= csrfToken() ?>"
                               class="text-red-500 p-1 hover:text-red-700" title="Delete"
                               onclick="return confirm('Permanently delete user <?= h(addslashes($u['name'])) ?>?')">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag, 'users.php?' . http_build_query(array_filter(compact('search','roleFilter','bizFilter'))) . '&') ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
