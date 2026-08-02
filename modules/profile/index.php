<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();

$db      = getDB();
$me      = currentUser();
$errors  = [];
$success = '';

// Fetch full user row
$stmt = $db->prepare('SELECT u.*, r.name AS role_name, b.name AS business_name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN businesses b ON b.id=u.business_id WHERE u.id=?');
$stmt->execute([$me['id']]);
$user = $stmt->fetch();

// ==================== UPDATE PROFILE ====================
if (isPost() && isset($_POST['update_profile'])) {
    verifyCsrf();
    $name  = post('name');
    $email = post('email');
    $phone = post('phone');

    if (empty($name))  $errors[] = 'Name is required.';
    if (empty($email)) $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    // Unique email check
    $dupStmt = $db->prepare('SELECT id FROM users WHERE email=? AND id!=?');
    $dupStmt->execute([$email, $me['id']]);
    if ($dupStmt->fetch()) $errors[] = 'Email is already used by another account.';

    if (empty($errors)) {
        $db->prepare('UPDATE users SET name=?,email=?,phone=? WHERE id=?')
           ->execute([$name, $email, $phone?:null, $me['id']]);
        // Update session
        $_SESSION['user_name']  = $name;
        $_SESSION['user_email'] = $email;
        auditLog('update', 'profile', $me['id'], [], ['name'=>$name,'email'=>$email]);
        $success = 'Profile updated successfully.';
        // Reload user row
        $stmt->execute([$me['id']]);
        $user = $stmt->fetch();
    }
}

// ==================== CHANGE PASSWORD ====================
if (isPost() && isset($_POST['change_password'])) {
    verifyCsrf();
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (empty($current)) $errors[] = 'Current password is required.';
    if (empty($new))     $errors[] = 'New password is required.';
    if (strlen($new) < 6) $errors[] = 'New password must be at least 6 characters.';
    if ($new !== $confirm) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        // Verify current password
        $pwStmt = $db->prepare('SELECT password FROM users WHERE id=?');
        $pwStmt->execute([$me['id']]);
        $hash = $pwStmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $errors[] = 'Current password is incorrect.';
        } else {
            $newHash = password_hash($new, PASSWORD_BCRYPT);
            $db->prepare('UPDATE users SET password=? WHERE id=?')->execute([$newHash, $me['id']]);
            auditLog('password_change', 'profile', $me['id']);
            $success = 'Password changed successfully.';
        }
    }
}

// ==================== UPLOAD AVATAR ====================
if (isPost() && isset($_POST['upload_avatar'])) {
    verifyCsrf();
    if (!empty($_FILES['avatar']['tmp_name'])) {
        $file    = $_FILES['avatar'];
        $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowed)) {
            $errors[] = 'Only JPG, PNG, WebP, or GIF images are allowed.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Image must be 2 MB or smaller.';
        } else {
            $ext      = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime];
            $filename = 'avatar_' . $me['id'] . '_' . time() . '.' . $ext;
            $destDir  = dirname(__DIR__, 2) . '/public/uploads/avatars/';
            $destPath = $destDir . $filename;
            // Remove old avatar file if it exists
            if (!empty($user['avatar'])) {
                $old = $destDir . basename($user['avatar']);
                if (is_file($old)) @unlink($old);
            }
            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $avatarUrl = 'uploads/avatars/' . $filename;
                $db->prepare('UPDATE users SET avatar=? WHERE id=?')->execute([$avatarUrl, $me['id']]);
                $_SESSION['user_avatar'] = $avatarUrl;
                auditLog('upload_avatar', 'profile', $me['id']);
                $success = 'Profile picture updated.';
                $stmt->execute([$me['id']]);
                $user = $stmt->fetch();
            } else {
                $errors[] = 'Failed to save image. Check folder permissions.';
            }
        }
    } else {
        $errors[] = 'No file selected.';
    }
}

// ==================== REMOVE AVATAR ====================
if (isPost() && isset($_POST['remove_avatar'])) {
    verifyCsrf();
    if (!empty($user['avatar'])) {
        $old = dirname(__DIR__, 2) . '/public/' . $user['avatar'];
        if (is_file($old)) @unlink($old);
    }
    $db->prepare('UPDATE users SET avatar=NULL WHERE id=?')->execute([$me['id']]);
    $_SESSION['user_avatar'] = null;
    $success = 'Profile picture removed.';
    $stmt->execute([$me['id']]);
    $user = $stmt->fetch();
}

// ==================== UPDATE BUSINESS LOGO ====================
if (isPost() && isset($_POST['update_biz_logo']) && !isAdmin() && !empty($user['business_id'])) {
    verifyCsrf();
    $bizId = (int)$user['business_id'];
    if (!empty($_FILES['biz_logo']['tmp_name'])) {
        $file    = $_FILES['biz_logo'];
        $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowed)) {
            $errors[] = 'Only JPG, PNG, WebP, or GIF images are allowed.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Logo image must be 2 MB or smaller.';
        } else {
            $ext     = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime];
            $fname   = 'biz_' . $bizId . '_' . time() . '.' . $ext;
            $dir     = dirname(__DIR__, 2) . '/uploads/businesses/';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            // Remove old logo
            $oldStmt = $db->prepare('SELECT logo FROM businesses WHERE id=?');
            $oldStmt->execute([$bizId]);
            $oldLogo = $oldStmt->fetchColumn();
            if ($oldLogo) { $old = dirname(__DIR__, 2) . '/' . $oldLogo; if (is_file($old)) @unlink($old); }
            if (move_uploaded_file($file['tmp_name'], $dir . $fname)) {
                $db->prepare('UPDATE businesses SET logo=? WHERE id=?')
                   ->execute(['uploads/businesses/' . $fname, $bizId]);
                auditLog('update_logo', 'businesses', $bizId);
                $success = 'Business logo updated successfully.';
            } else {
                $errors[] = 'Failed to save logo. Check folder permissions.';
            }
        }
    } else {
        $errors[] = 'No file selected.';
    }
}

// ==================== REMOVE BUSINESS LOGO ====================
if (isPost() && isset($_POST['remove_biz_logo']) && !isAdmin() && !empty($user['business_id'])) {
    verifyCsrf();
    $bizId = (int)$user['business_id'];
    $oldStmt = $db->prepare('SELECT logo FROM businesses WHERE id=?');
    $oldStmt->execute([$bizId]);
    $oldLogo = $oldStmt->fetchColumn();
    if ($oldLogo) { $old = dirname(__DIR__, 2) . '/' . $oldLogo; if (is_file($old)) @unlink($old); }
    $db->prepare('UPDATE businesses SET logo=NULL WHERE id=?')->execute([$bizId]);
    auditLog('remove_logo', 'businesses', $bizId);
    $success = 'Business logo removed.';
}

// Recent activity
$activityStmt = $db->prepare('SELECT action, module, created_at FROM audit_logs WHERE user_id=? ORDER BY created_at DESC LIMIT 10');
$activityStmt->execute([$me['id']]);
$activities = $activityStmt->fetchAll();

// Fetch fresh business logo (in case it was just updated)
$__bizLogoData = null;
if (!isAdmin() && !empty($user['business_id'])) {
    $__blStmt = $db->prepare('SELECT logo, name FROM businesses WHERE id=? LIMIT 1');
    $__blStmt->execute([$user['business_id']]);
    $__bizLogoData = $__blStmt->fetch();
}

$pageTitle = 'My Profile';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
// sidebar.php overwrites $user with session data; re-fetch from DB to restore full row
$stmt->execute([$me['id']]);
$user = $stmt->fetch();
?>

<div class="max-w-4xl mx-auto">
    <h2 class="text-xl font-bold text-gray-800 mb-6">My Profile</h2>

    <?php if ($success): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-4">
        <i class="fa-solid fa-circle-check mr-1"></i> <?= h($success) ?>
    </div>
    <?php endif; ?>
    <?php if ($errors): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
        <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><i class="fa-solid fa-circle-exclamation mr-1"></i><?= h($e) ?></p><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Profile Card -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 text-center">
                <?php $avatarUrl = !empty($user['avatar']) ? APP_URL . '/' . h($user['avatar']) : null; ?>
                <div class="relative inline-block mb-4">
                    <?php if ($avatarUrl): ?>
                    <img src="<?= $avatarUrl ?>" alt="Avatar"
                         class="w-20 h-20 rounded-full object-cover border-2 border-blue-200 mx-auto">
                    <?php else: ?>
                    <div class="w-20 h-20 rounded-full bg-blue-600 text-white text-3xl font-bold flex items-center justify-center mx-auto">
                        <?= strtoupper(substr($user['name'], 0, 1)) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Upload form -->
                <form method="POST" enctype="multipart/form-data" class="mb-3">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="upload_avatar" value="1">
                    <label class="cursor-pointer inline-flex items-center gap-1.5 text-xs font-medium text-blue-600 hover:text-blue-800 border border-blue-300 rounded-lg px-3 py-1.5">
                        <i class="fa-solid fa-camera"></i>
                        <span><?= $avatarUrl ? 'Change Photo' : 'Upload Photo' ?></span>
                        <input type="file" name="avatar" accept="image/*" class="hidden" onchange="this.form.submit()">
                    </label>
                </form>
                <?php if ($avatarUrl): ?>
                <form method="POST" class="mb-3">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="remove_avatar" value="1">
                    <button type="submit" class="text-xs text-red-500 hover:text-red-700">
                        <i class="fa-solid fa-trash-can mr-1"></i>Remove Photo
                    </button>
                </form>
                <?php endif; ?>
                <h3 class="text-lg font-bold text-gray-800"><?= h($user['name']) ?></h3>
                <p class="text-sm text-gray-500 mb-1"><?= h($user['email']) ?></p>
                <p class="text-xs text-gray-400"><?= h($user['phone'] ?? 'No phone') ?></p>
                <div class="mt-3">
                    <span class="px-3 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800"><?= h($user['role_name']) ?></span>
                </div>
                <?php if ($user['business_name']): ?>
                <div class="mt-4 pt-4 border-t text-sm text-gray-500">
                    <i class="fa-solid fa-building mr-1"></i> <?= h($user['business_name']) ?>
                </div>
                <?php endif; ?>
                <div class="mt-3 text-xs text-gray-400">
                    <p>Last login: <?= $user['last_login'] ? formatDateTime($user['last_login']) : 'N/A' ?></p>
                    <p>Member since: <?= formatDate($user['created_at']) ?></p>
                </div>
            </div>

            <!-- Recent Activity -->
            <?php if ($activities): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mt-4">
                <h4 class="text-sm font-semibold text-gray-700 mb-3">Recent Activity</h4>
                <div class="space-y-2">
                    <?php foreach ($activities as $act): ?>
                    <div class="flex items-start gap-2 text-xs text-gray-500">
                        <div class="w-5 h-5 rounded-full bg-gray-100 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <i class="fa-solid fa-clock-rotate-left text-gray-400 text-xs"></i>
                        </div>
                        <div>
                            <span class="capitalize font-medium text-gray-700"><?= h($act['action']) ?></span>
                            <span class="text-gray-400"> in <?= h($act['module']) ?></span>
                            <p class="text-gray-300"><?= formatDateTime($act['created_at']) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Forms -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Update Profile -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h4 class="text-base font-semibold text-gray-800 mb-4">
                    <i class="fa-solid fa-user-pen mr-2 text-blue-600"></i>Update Profile
                </h4>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Full Name *</label>
                            <input type="text" name="name" value="<?= h($user['name']) ?>" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email Address *</label>
                            <input type="email" name="email" value="<?= h($user['email']) ?>" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                            <input type="text" name="phone" value="<?= h($user['phone']??'') ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none" placeholder="+232...">
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                            <i class="fa-solid fa-save mr-1"></i> Save Profile
                        </button>
                    </div>
                </form>
            </div>

            <!-- Business Logo (business users only) -->
            <?php if (!isAdmin() && !empty($user['business_id']) && $__bizLogoData): ?>
            <?php
            $__currentBizLogo = $__bizLogoData['logo'] ?? null;
            $__bizLogoUrl2 = null;
            if ($__currentBizLogo) {
                $__bizLogoPath = dirname(__DIR__, 2) . '/' . $__currentBizLogo;
                if (is_file($__bizLogoPath)) $__bizLogoUrl2 = SITE_URL . '/' . $__currentBizLogo;
            }
            ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h4 class="text-base font-semibold text-gray-800 mb-1">
                    <i class="fa-solid fa-image mr-2 text-purple-500"></i>Business Logo
                </h4>
                <p class="text-sm text-gray-500 mb-5">Your business logo appears in the sidebar and on documents.</p>

                <div class="flex items-center gap-5 mb-5">
                    <!-- Current Logo Preview -->
                    <div class="flex-shrink-0">
                        <?php if ($__bizLogoUrl2): ?>
                        <img src="<?= h($__bizLogoUrl2) ?>" alt="Business Logo"
                             class="w-20 h-20 rounded-xl object-cover border-2 border-gray-200 shadow-sm" id="biz-logo-preview">
                        <?php else: ?>
                        <div class="w-20 h-20 rounded-xl bg-amber-50 border-2 border-amber-200 flex items-center justify-center" id="biz-logo-placeholder">
                            <span class="text-amber-500 font-bold text-3xl"><?= strtoupper(substr($__bizLogoData['name'] ?? 'B', 0, 1)) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-700"><?= h($__bizLogoData['name'] ?? '') ?></p>
                        <p class="text-xs text-gray-400 mt-0.5">Recommended: square image, at least 200×200 px, max 2 MB</p>
                        <p class="text-xs text-gray-400">Formats: JPG, PNG, WebP, GIF</p>
                    </div>
                </div>

                <!-- Upload Form -->
                <form method="POST" enctype="multipart/form-data" class="flex flex-wrap gap-3">
                    <input type="hidden" name="csrf_token"      value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="update_biz_logo" value="1">
                    <label class="cursor-pointer inline-flex items-center gap-2 bg-blue-600 text-white text-sm font-medium px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fa-solid fa-upload"></i>
                        <?= $__bizLogoUrl2 ? 'Replace Logo' : 'Upload Logo' ?>
                        <input type="file" name="biz_logo" accept="image/*" class="hidden"
                               onchange="previewBizLogo(this); this.form.submit();">
                    </label>
                    <?php if ($__bizLogoUrl2): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token"     value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="remove_biz_logo" value="1">
                        <button type="submit" onclick="return confirm('Remove business logo?')"
                                class="inline-flex items-center gap-2 border border-red-300 text-red-600 text-sm px-4 py-2 rounded-lg hover:bg-red-50">
                            <i class="fa-solid fa-trash-can"></i> Remove Logo
                        </button>
                    </form>
                    <?php endif; ?>
                </form>
            </div>
            <?php endif; ?>

            <!-- Change Password -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h4 class="text-base font-semibold text-gray-800 mb-4">
                    <i class="fa-solid fa-lock mr-2 text-amber-500"></i>Change Password
                </h4>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="change_password" value="1">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Current Password *</label>
                            <input type="password" name="current_password" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                                placeholder="Enter your current password">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">New Password *</label>
                            <input type="password" name="new_password" id="newPw" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                                placeholder="Min. 6 characters"
                                oninput="checkPwStrength(this.value)">
                            <div id="pw-strength" class="mt-1 h-1 rounded-full bg-gray-200 overflow-hidden">
                                <div id="pw-bar" class="h-full transition-all duration-300 rounded-full"></div>
                            </div>
                            <p id="pw-label" class="text-xs text-gray-400 mt-0.5"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password *</label>
                            <input type="password" name="confirm_password" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                                placeholder="Repeat new password">
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="bg-amber-500 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-amber-600">
                            <i class="fa-solid fa-key mr-1"></i> Change Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function previewBizLogo(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        const preview = document.getElementById('biz-logo-preview');
        const placeholder = document.getElementById('biz-logo-placeholder');
        if (preview) { preview.src = e.target.result; }
        else if (placeholder) {
            const img = document.createElement('img');
            img.src = e.target.result;
            img.className = 'w-20 h-20 rounded-xl object-cover border-2 border-gray-200 shadow-sm';
            img.id = 'biz-logo-preview';
            placeholder.replaceWith(img);
        }
    };
    reader.readAsDataURL(input.files[0]);
}

function checkPwStrength(pw) {
    const bar = document.getElementById('pw-bar');
    const lbl = document.getElementById('pw-label');
    let score = 0;
    if (pw.length >= 6) score++;
    if (pw.length >= 10) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    const levels = [
        {w:'0%', c:'', t:''},
        {w:'25%', c:'bg-red-400', t:'Weak'},
        {w:'50%', c:'bg-yellow-400', t:'Fair'},
        {w:'75%', c:'bg-blue-400', t:'Good'},
        {w:'100%', c:'bg-green-500', t:'Strong'},
    ];
    const l = levels[Math.min(score, 4)];
    bar.style.width = l.w;
    bar.className = 'h-full transition-all duration-300 rounded-full ' + l.c;
    lbl.textContent = l.t;
}
</script>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
