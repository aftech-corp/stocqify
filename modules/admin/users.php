<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
require_once __DIR__ . '/../../app/includes/mail.php';
requireLogin();

// Ensure email verification table, user columns, and user_feature_permissions table exist
$__db2 = getDB();
try { $__db2->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS force_password_change TINYINT NOT NULL DEFAULT 0"); } catch (\Exception $__e) {}
try { $__db2->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at DATETIME DEFAULT NULL"); } catch (\Exception $__e) {}
$__db2->exec("CREATE TABLE IF NOT EXISTS `user_feature_permissions` (
    `user_id`     INT UNSIGNED NOT NULL,
    `feature_key` VARCHAR(100) NOT NULL,
    `status`      ENUM('enabled','disabled') NOT NULL DEFAULT 'enabled',
    PRIMARY KEY (`user_id`, `feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$__db2->exec("CREATE TABLE IF NOT EXISTS `email_verifications` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `token`      VARCHAR(64)  NOT NULL,
    `expires_at` DATETIME     NOT NULL,
    `used_at`    DATETIME     DEFAULT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ev_token` (`token`),
    KEY `idx_ev_user`  (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
unset($__db2, $__e);
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

// Load feature definitions for the access-control panel
$allFeatureKeys = require APP_PATH . '/includes/feature_definitions.php';

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

    // Business features the plan currently allows (for the feature-access panel)
    $planEnabledFeatures = [];
    $existingUserPerms   = []; // feature_key => status for the user being edited
    $showFeaturePanel    = false;

    // Determine the target business id (may differ between admin vs owner context)
    $targetBizId = $ownerBizId ?? (int)($user_data['business_id'] ?? 0);

    if ($targetBizId) {
        try {
            $pfQ = $db->prepare("SELECT feature_key FROM business_features WHERE business_id=? AND status='enabled'");
            $pfQ->execute([$targetBizId]);
            $planEnabledFeatures = $pfQ->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $__e) { $planEnabledFeatures = []; }

        if ($action === 'edit' && $userId) {
            try {
                $upQ = $db->prepare("SELECT feature_key, status FROM user_feature_permissions WHERE user_id=?");
                $upQ->execute([$userId]);
                $existingUserPerms = $upQ->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (\Exception $__e) { $existingUserPerms = []; }
        }
        $showFeaturePanel = !empty($planEnabledFeatures);
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
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address format.';
        } elseif ($action === 'add') {
            // Verify the email domain actually exists and can receive mail
            $emailDomain = substr($email, strrpos($email, '@') + 1);
            if (!checkdnsrr($emailDomain, 'MX') && !checkdnsrr($emailDomain, 'A')) {
                $errors[] = "The email domain '{$emailDomain}' does not appear to be valid or cannot receive emails.";
            }
        }
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
                $stmt = $db->prepare('INSERT INTO users (business_id,role_id,name,email,phone,password,is_active,force_password_change) VALUES (?,?,?,?,?,?,?,1)');
                $stmt->execute([$bizId, $roleId, $name, $email, $phone?:null, $hash, $isActive]);
                $newUserId = (int)$db->lastInsertId();
                auditLog('create', 'users', $newUserId, [], ['name'=>$name,'email'=>$email]);

                // Generate email verification token and send welcome email
                $vToken   = bin2hex(random_bytes(32));
                $vExpires = date('Y-m-d H:i:s', strtotime('+48 hours'));
                $db->prepare("INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?,?,?)")
                   ->execute([$newUserId, $vToken, $vExpires]);
                $vLink = url('verify-email') . '?token=' . $vToken;
                $loginLink = url('login');
                $appName   = h(APP_NAME);
                $safeName  = h($name);
                $welcomeHtml = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f1f5f9;font-family:system-ui,sans-serif;'>
  <div style='max-width:520px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);'>
    <div style='background:linear-gradient(135deg,#1B3263,#2952a3);padding:32px 40px;text-align:center;'>
      <img src='" . APP_LOGO . "' alt='{$appName}' style='height:48px;width:auto;border-radius:10px;margin-bottom:12px;'>
      <h1 style='color:#fff;font-size:20px;font-weight:700;margin:0;'>{$appName}</h1>
    </div>
    <div style='padding:36px 40px;'>
      <h2 style='font-size:18px;font-weight:700;color:#1e293b;margin:0 0 8px;'>Welcome, {$safeName}!</h2>
      <p style='color:#64748b;font-size:14px;margin:0 0 16px;line-height:1.6;'>
        Your {$appName} account has been created by an administrator. Please verify your email address to activate your account.
      </p>
      <p style='color:#64748b;font-size:14px;margin:0 0 8px;line-height:1.6;'>
        <strong>Your login email:</strong> {$email}<br>
        <strong>Temporary password:</strong> <em>provided by your administrator</em>
      </p>
      <p style='color:#94a3b8;font-size:13px;margin:0 0 24px;'>You will be required to set a new password on your first login.</p>
      <a href='{$vLink}'
         style='display:block;background:#C9A84C;color:#fff;text-decoration:none;text-align:center;
                padding:14px 24px;border-radius:8px;font-weight:600;font-size:15px;margin-bottom:20px;'>
        Verify Email Address
      </a>
      <a href='{$loginLink}'
         style='display:block;background:#1B3263;color:#fff;text-decoration:none;text-align:center;
                padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px;margin-bottom:24px;'>
        Sign In Now
      </a>
      <p style='color:#94a3b8;font-size:12px;'>This verification link expires in 48 hours.</p>
    </div>
    <div style='background:#f8fafc;padding:16px 40px;text-align:center;border-top:1px solid #e2e8f0;'>
      <p style='color:#94a3b8;font-size:11px;margin:0;'>&copy; {$appName} &middot; Automated message, do not reply.</p>
    </div>
  </div>
</body></html>";
                appMail($email, "Welcome to {$appName} — Verify Your Email", $welcomeHtml, $name);

                flash('success', "User '{$name}' created. A welcome &amp; verification email has been sent to {$email}.");
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

            // Save per-user feature permissions when a business context exists
            $__targetUid = $action === 'add' ? ($newUserId ?? 0) : $userId;
            if ($__targetUid && !empty($planEnabledFeatures)) {
                $db->prepare("DELETE FROM user_feature_permissions WHERE user_id=?")->execute([$__targetUid]);
                $__fpStmt = $db->prepare("INSERT INTO user_feature_permissions (user_id, feature_key, status) VALUES (?,?,?)");
                $__selectedFeatures = $_POST['user_features'] ?? [];
                foreach ($planEnabledFeatures as $__fk) {
                    $__fpStmt->execute([$__targetUid, $__fk, in_array($__fk, $__selectedFeatures) ? 'enabled' : 'disabled']);
                }
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
    <div>
        <div class="flex items-center gap-3 mb-6">
            <a href="users.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
            <h2 class="text-xl font-bold text-gray-800"><?= $pageTitle ?></h2>
        </div>

        <?php if ($errors): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
            <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

                <!-- ── Left: Account Details ── -->
                <div class="xl:col-span-2 space-y-6">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center gap-2 mb-4 pb-2 border-b border-gray-100">
                            <i class="fa-solid fa-user-circle text-gray-400 text-sm"></i>
                            <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">Account Details</span>
                        </div>
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
                                <select name="role_id" id="roleSelect" required class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">-- Select Role --</option>
                                    <?php foreach ($roles as $r): ?>
                                    <option value="<?= $r['id'] ?>" data-slug="<?= h($r['slug']) ?>" <?= ($user_data['role_id'] ?? '') == $r['id'] ? 'selected' : '' ?>><?= h($r['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if (isAdmin()): ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Business</label>
                                <select name="business_id" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
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
                                <label class="block text-sm font-medium text-gray-700 mb-1">Password<?= $action === 'edit' ? ' <span class="font-normal text-gray-400">(leave blank to keep current)</span>' : ' *' ?></label>
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
                    </div>
                </div>

                <!-- ── Right: Feature Access ── -->
                <div class="xl:col-span-1">
                    <?php if ($showFeaturePanel): ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 xl:sticky xl:top-6" id="featurePanel">
                        <div class="flex items-center justify-between mb-3 pb-2 border-b border-gray-100">
                            <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                                <i class="fa-solid fa-shield-halved mr-1"></i> Feature Access
                            </span>
                            <div class="flex gap-2">
                                <button type="button" onclick="setAllFeatures(true)" class="text-xs text-blue-600 hover:text-blue-800">All</button>
                                <span class="text-gray-300">|</span>
                                <button type="button" onclick="setAllFeatures(false)" class="text-xs text-gray-500 hover:text-gray-700">None</button>
                            </div>
                        </div>
                        <p class="text-xs text-gray-400 mb-3 leading-relaxed">
                            Control which features this user can access. Only features included in your current plan are shown.
                        </p>
                        <?php
                        // Group plan-enabled features by section for display
                        $panelSections = [];
                        foreach ($allFeatureKeys as $fk => $fm) {
                            if (in_array($fk, $planEnabledFeatures)) {
                                $panelSections[$fm['section']][$fk] = $fm;
                            }
                        }
                        foreach ($panelSections as $sectionName => $sectionFeatures):
                        ?>
                        <div class="mb-4">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5"><?= h($sectionName) ?></p>
                            <div class="space-y-1">
                            <?php foreach ($sectionFeatures as $fk => $fm):
                                // Default: enabled (all features on) for new users; use saved perm for existing
                                $isChecked = empty($existingUserPerms)
                                    ? true
                                    : (($existingUserPerms[$fk] ?? 'enabled') === 'enabled');
                                // On POST validation failure, respect what was submitted
                                if (isPost()) $isChecked = in_array($fk, $_POST['user_features'] ?? []);
                            ?>
                            <label class="flex items-center gap-2 cursor-pointer py-0.5 group">
                                <input type="checkbox" name="user_features[]" value="<?= h($fk) ?>"
                                    class="feat-cb w-3.5 h-3.5 rounded text-blue-600 flex-shrink-0"
                                    <?= $isChecked ? 'checked' : '' ?>>
                                <span class="text-xs text-gray-600 group-hover:text-gray-900 leading-tight"><?= h($fm['label']) ?></span>
                            </label>
                            <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($panelSections)): ?>
                        <p class="text-xs text-gray-400 italic text-center py-4">No features available.<br>Contact your admin to configure your plan.</p>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="bg-gray-50 border border-dashed border-gray-200 rounded-xl p-5 text-center">
                        <i class="fa-solid fa-shield-halved text-2xl text-gray-300 mb-2 block"></i>
                        <p class="text-xs text-gray-400 leading-relaxed">Feature access controls will appear here once this user is assigned to a business with an active plan.</p>
                    </div>
                    <?php endif; ?>
                </div>

            </div><!-- /grid -->

            <div class="flex gap-3 mt-6">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700">
                    <i class="fa-solid fa-save mr-1"></i> <?= $action === 'add' ? 'Create User' : 'Save Changes' ?>
                </button>
                <a href="users.php" class="bg-gray-100 text-gray-700 px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
            </div>
        </form>
    </div>

    <script>
    function setAllFeatures(checked) {
        document.querySelectorAll('.feat-cb').forEach(cb => cb.checked = checked);
    }
    // Hide feature panel for business-owner role (owners always get full access)
    const roleSelect = document.getElementById('roleSelect');
    const featurePanel = document.getElementById('featurePanel');
    if (roleSelect && featurePanel) {
        function togglePanel() {
            const slug = roleSelect.options[roleSelect.selectedIndex]?.dataset?.slug || '';
            featurePanel.style.opacity = (slug === 'owner') ? '0.4' : '1';
            featurePanel.querySelectorAll('input[type=checkbox]').forEach(cb => cb.disabled = (slug === 'owner'));
        }
        roleSelect.addEventListener('change', togglePanel);
        togglePanel();
    }
    </script>
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
