<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
require_once __DIR__ . '/../../app/includes/image_uploader.php';
require_once __DIR__ . '/../../app/includes/countries.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    include APP_PATH . '/includes/403.php';
    exit;
}

$db     = getDB();
$action = get('action', 'list');
$bizId  = (int)get('id', 0);
$errors = [];

// Ensure logo and business_type columns exist
try { $db->exec("ALTER TABLE businesses ADD COLUMN IF NOT EXISTS logo VARCHAR(255) DEFAULT NULL"); } catch (\Exception $e) {}
try { $db->exec("ALTER TABLE businesses ADD COLUMN IF NOT EXISTS business_type ENUM('products','services') NOT NULL DEFAULT 'products'"); } catch (\Exception $e) {}
try { $db->exec("ALTER TABLE businesses ADD COLUMN IF NOT EXISTS country VARCHAR(100) DEFAULT NULL"); } catch (\Exception $e) {}

function _uploadBizLogo(array $file, int $id): ?string {
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!in_array($file['type'], $allowed) || $file['size'] > 2 * 1024 * 1024) return null;
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $name = 'biz_' . $id . '_' . time() . '.' . $ext;
    $dir  = __DIR__ . '/../../uploads/businesses/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $dest = $dir . $name;
    return move_uploaded_file($file['tmp_name'], $dest) ? 'uploads/businesses/' . $name : null;
}

// Load available currencies from system_settings (falls back to defaults if table/data missing)
try {
    $__cr = $db->query("SELECT value FROM system_settings WHERE `key`='currencies'");
    $__cj = $__cr ? $__cr->fetchColumn() : null;
    $availCurrencies = $__cj ? (json_decode($__cj, true) ?: []) : [];
} catch (Exception $e) { $availCurrencies = []; }
if (empty($availCurrencies)) {
    $availCurrencies = [
        ['code'=>'NLE','name'=>'Sierra Leone Leone (New)','symbol'=>'NLE'],
        ['code'=>'SLE','name'=>'Sierra Leone Leone','symbol'=>'Le'],
        ['code'=>'USD','name'=>'US Dollar','symbol'=>'$'],
        ['code'=>'GBP','name'=>'British Pound','symbol'=>'£'],
        ['code'=>'EUR','name'=>'Euro','symbol'=>'€'],
    ];
}

// ==================== DELETE ====================
if ($action === 'delete' && $bizId) {
    verifyCsrf();
    // Check if business has users
    $hasUsers = $db->prepare('SELECT COUNT(*) FROM users WHERE business_id=?');
    $hasUsers->execute([$bizId]);
    if ($hasUsers->fetchColumn() > 0) {
        flash('error', 'Cannot delete a business that still has users assigned to it. Reassign or delete users first.');
    } else {
        $db->prepare('DELETE FROM businesses WHERE id=?')->execute([$bizId]);
        auditLog('delete', 'businesses', $bizId);
        flash('success', 'Business deleted successfully.');
    }
    redirect(url('admin/businesses'));
}

// ==================== TOGGLE ACTIVE ====================
if ($action === 'toggle' && $bizId) {
    verifyCsrf();
    $stmt = $db->prepare('SELECT is_active FROM businesses WHERE id=?');
    $stmt->execute([$bizId]);
    $current = (int)$stmt->fetchColumn();
    $db->prepare('UPDATE businesses SET is_active=? WHERE id=?')->execute([$current ? 0 : 1, $bizId]);
    flash('success', 'Business status updated.');
    redirect(url('admin/businesses'));
}

// ==================== ADD / EDIT ====================
if (in_array($action, ['add', 'edit'])) {
    $biz = ['name'=>'','address'=>'','phone'=>'','email'=>'','currency'=>'NLE','is_active'=>1,'logo'=>'','business_type'=>'products','country'=>''];
    if ($action === 'edit' && $bizId) {
        $stmt = $db->prepare('SELECT * FROM businesses WHERE id=?');
        $stmt->execute([$bizId]);
        $biz = $stmt->fetch() ?: $biz;
    }

    if (isPost()) {
        verifyCsrf();
        $name         = post('name');
        $address      = post('address');
        $phone        = post('phone');
        $email        = post('email');
        $currency     = post('currency', 'NLE');
        $isActive     = (int)post('is_active', 1);
        $businessType = in_array(post('business_type'), ['products','services']) ? post('business_type') : 'products';
        $country      = post('country', '');

        if (empty($name)) $errors[] = 'Business name is required.';
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

        if (empty($errors)) {
            if ($action === 'add') {
                $stmt = $db->prepare('INSERT INTO businesses (name,address,phone,email,currency,is_active,business_type,country) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$name, $address?:null, $phone?:null, $email?:null, $currency, $isActive, $businessType, $country?:null]);
                $newId = (int)$db->lastInsertId();
                // Logo upload
                if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $logoPath = _uploadBizLogo($_FILES['logo'], $newId);
                    if ($logoPath) $db->prepare('UPDATE businesses SET logo=? WHERE id=?')->execute([$logoPath, $newId]);
                }
                auditLog('create', 'businesses', $newId, [], compact('name'));
                flash('success', "Business '{$name}' created successfully.");
            } else {
                $curLogo = $biz['logo'] ?? null;
                // New logo
                if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $uploaded = _uploadBizLogo($_FILES['logo'], $bizId);
                    if ($uploaded) {
                        if ($curLogo) @unlink(__DIR__ . '/../../' . $curLogo);
                        $curLogo = $uploaded;
                    }
                }
                // Remove logo
                if (post('remove_logo') === '1' && $curLogo) {
                    @unlink(__DIR__ . '/../../' . $curLogo);
                    $curLogo = null;
                }
                $stmt = $db->prepare('UPDATE businesses SET name=?,address=?,phone=?,email=?,currency=?,is_active=?,logo=?,business_type=?,country=? WHERE id=?');
                $stmt->execute([$name, $address?:null, $phone?:null, $email?:null, $currency, $isActive, $curLogo, $businessType, $country?:null, $bizId]);
                auditLog('update', 'businesses', $bizId, [], compact('name'));
                flash('success', "Business '{$name}' updated successfully.");
            }
            redirect(url('admin/businesses'));
        }
        $biz = array_merge($biz, compact('name','address','phone','email','currency','isActive','country'));
    }

    $pageTitle = $action === 'add' ? 'Add Business' : 'Edit Business';
    include __DIR__ . '/../../app/includes/header.php';
    include __DIR__ . '/../../app/includes/sidebar.php';
    ?>
    <div>
        <div class="flex items-center gap-3 mb-6">
            <a href="businesses.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
            <div>
                <h2 class="text-xl font-bold text-gray-800"><?= $pageTitle ?></h2>
                <p class="text-sm text-gray-400">Fill in the business details below</p>
            </div>
        </div>
        <?php if ($errors): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
            <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php
        $existingLogoUrl = (!empty($biz['logo']) && file_exists(__DIR__ . '/../../' . $biz['logo']))
            ? SITE_URL . '/' . $biz['logo'] : '';
        $__countries = countriesList();
        $__curCountry = $biz['country'] ?? '';
        ?>
        <div class="grid grid-cols-1 xl:grid-cols-4 gap-6">

        <!-- ── Main form (3/4 width) ──────────────────────── -->
        <div class="xl:col-span-3">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

                <!-- Section: Basic Info -->
                <div class="flex items-center gap-2 mb-4 pb-2 border-b border-gray-100">
                    <i class="fa-solid fa-building text-gray-400 text-sm"></i>
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">Business Information</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Business Name *</label>
                        <input type="text" name="name" value="<?= h($biz['name']) ?>" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="e.g. Acme Corp Ltd.">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Country</label>
                        <select name="country" id="biz-country"
                            class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500"
                            onchange="bizCountryChange(this)">
                            <option value="">-- Select Country --</option>
                            <?php foreach ($__countries as $c): ?>
                            <option value="<?= h($c['code']) ?>"
                                <?= $__curCountry === $c['code'] ? 'selected' : '' ?>
                                data-dial="<?= h($c['dial']) ?>"
                                data-currency="<?= h($c['currency'] ?? '') ?>">
                                <?= h($c['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input type="text" name="phone" id="biz-phone" value="<?= h($biz['phone']??'') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="+xxx xxx xxxx">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Business Email</label>
                        <input type="email" name="email" value="<?= h($biz['email']??'') ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                            placeholder="contact@business.com">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
                        <select name="currency" id="biz-currency" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                            <?php foreach ($availCurrencies as $curr): ?>
                            <option value="<?= h($curr['code']) ?>" <?= ($biz['currency']??'NLE')===$curr['code']?'selected':'' ?>>
                                <?= h($curr['code'] . ' — ' . $curr['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">Auto-suggested from country. <a href="<?= url('admin/settings') ?>?tab=currencies" class="text-blue-500">Add currencies</a></p>
                    </div>
                    <div class="flex items-end pb-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="is_active" value="1" <?= $biz['is_active']?'checked':'' ?> class="w-4 h-4 rounded text-blue-600">
                            <span class="text-sm font-medium text-gray-700">Business Active</span>
                        </label>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                        <textarea name="address" rows="2"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"
                            placeholder="Street, city, state/province..."><?= h($biz['address']??'') ?></textarea>
                    </div>
                </div>

                <!-- Section: Business Type -->
                <div class="flex items-center gap-2 mb-4 pb-2 border-b border-gray-100">
                    <i class="fa-solid fa-tag text-gray-400 text-sm"></i>
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">Business Type</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-6">
                    <?php foreach ([
                        'products' => ['icon'=>'fa-boxes-stacked',       'title'=>'Product Business',  'desc'=>'Sells physical products and tracks inventory stock'],
                        'services' => ['icon'=>'fa-hand-holding-heart',  'title'=>'Service Business',  'desc'=>'Offers services to clients — appointments, jobs, consultations'],
                    ] as $val => $opt): ?>
                    <?php $sel = ($biz['business_type'] ?? 'products') === $val; ?>
                    <label class="flex items-start gap-3 p-4 border-2 rounded-xl cursor-pointer transition-colors <?= $sel ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-gray-300' ?>" id="btype-label-<?= $val ?>">
                        <input type="radio" name="business_type" value="<?= $val ?>" <?= $sel ? 'checked' : '' ?>
                            class="mt-0.5"
                            onchange="document.querySelectorAll('[id^=btype-label-]').forEach(el=>el.classList.remove('border-blue-500','bg-blue-50'));this.closest('label').classList.add('border-blue-500','bg-blue-50')">
                        <div>
                            <p class="font-semibold text-gray-800 text-sm flex items-center gap-1.5">
                                <i class="fa-solid <?= $opt['icon'] ?> text-blue-600"></i> <?= $opt['title'] ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-0.5"><?= $opt['desc'] ?></p>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700">
                        <i class="fa-solid fa-save mr-1"></i> <?= $action==='add'?'Create Business':'Save Changes' ?>
                    </button>
                    <a href="businesses.php" class="bg-gray-100 text-gray-700 px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
                </div>
            </form>
        </div>
        </div><!-- /xl:col-span-3 -->

        <!-- ── Logo uploader (1/4 width) ──────────────────── -->
        <div class="xl:col-span-1">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 xl:sticky xl:top-6">
                <?= renderImageUploader(
                    'bizlogo',
                    'logo',
                    'Business Logo <span class="text-gray-400 text-xs font-normal">(optional)</span>',
                    $existingLogoUrl,
                    $action === 'edit' ? 'remove_logo' : '',
                    '200px'
                ) ?>
                <p class="text-xs text-gray-400 mt-3 text-center">PNG, JPG, WEBP — max 2 MB</p>
            </div>
        </div>

        </div><!-- /grid -->
    </div>
    <script>
    function bizCountryChange(sel) {
        const opt  = sel.options[sel.selectedIndex];
        const dial = opt.dataset.dial || '';
        const cur  = opt.dataset.currency || '';
        // Update phone placeholder with dial code
        const ph = document.getElementById('biz-phone');
        if (ph && dial && !ph.value) ph.placeholder = dial + ' xxx xxxx';
        // Try to auto-select currency if it exists in list
        if (cur) {
            const cs = document.getElementById('biz-currency');
            if (cs) {
                for (let i = 0; i < cs.options.length; i++) {
                    if (cs.options[i].value === cur) { cs.selectedIndex = i; break; }
                }
            }
        }
    }
    </script>
    <?php imageUploaderAssets(); ?>
    <?php
    include __DIR__ . '/../../app/includes/footer.php';
    exit;
}

// ==================== LIST VIEW ====================
$search = get('search');
$page   = max(1, (int)get('page', 1));

$where  = '1=1';
$params = [];
if ($search) {
    $where .= ' AND (b.name LIKE ? OR b.email LIKE ? OR b.phone LIKE ?)';
    $s = "%{$search}%";
    $params = [$s, $s, $s];
}

$totalQ = $db->prepare("SELECT COUNT(*) FROM businesses b WHERE $where");
$totalQ->execute($params);
$total = (int)$totalQ->fetchColumn();
$pag   = paginate($total, $page);

$stmt = $db->prepare("SELECT b.*,
    (SELECT COUNT(*) FROM users u WHERE u.business_id=b.id) AS user_count,
    (SELECT COUNT(*) FROM customers c WHERE c.business_id=b.id) AS customer_count,
    (SELECT COUNT(*) FROM sales s WHERE s.business_id=b.id) AS sale_count,
    (SELECT COALESCE(SUM(s2.total_amount),0) FROM sales s2 WHERE s2.business_id=b.id) AS total_revenue
    FROM businesses b WHERE $where
    ORDER BY b.created_at DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$businesses = $stmt->fetchAll();

$pageTitle = 'Business Management';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Business Management</h2>
        <p class="text-sm text-gray-500"><?= number_format($total) ?> business<?= $total!=1?'es':'' ?> registered</p>
    </div>
    <a href="businesses.php?action=add" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
        <i class="fa-solid fa-plus mr-1"></i> Add Business
    </a>
</div>

<!-- Search -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex gap-3">
        <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search businesses..."
            class="flex-1 border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Search</button>
        <a href="businesses.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Clear</a>
    </form>
</div>

<!-- Table -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Business</th>
                    <th class="px-4 py-3 font-medium text-center">Users</th>
                    <th class="px-4 py-3 font-medium text-center">Customers</th>
                    <th class="px-4 py-3 font-medium text-center">Sales</th>
                    <th class="px-4 py-3 font-medium text-right">Total Revenue</th>
                    <th class="px-4 py-3 font-medium text-center">Status</th>
                    <th class="px-4 py-3 font-medium text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($businesses)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No businesses found.</td></tr>
                <?php else: ?>
                <?php foreach ($businesses as $b): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <?php if (!empty($b['logo']) && file_exists(__DIR__ . '/../../' . $b['logo'])): ?>
                            <img src="<?= SITE_URL . '/' . h($b['logo']) ?>" alt=""
                                 class="w-9 h-9 rounded-lg object-cover border border-gray-200 flex-shrink-0">
                            <?php else: ?>
                            <div class="w-9 h-9 rounded-lg bg-blue-600 text-white flex items-center justify-center font-bold flex-shrink-0">
                                <?= strtoupper(substr($b['name'],0,1)) ?>
                            </div>
                            <?php endif; ?>
                            <div>
                                <p class="font-semibold text-gray-800"><?= h($b['name']) ?></p>
                                <p class="text-xs text-gray-400"><?= h($b['email'] ?? $b['phone'] ?? 'No contact') ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center font-medium"><?= number_format($b['user_count']) ?></td>
                    <td class="px-4 py-3 text-center text-gray-500"><?= number_format($b['customer_count']) ?></td>
                    <td class="px-4 py-3 text-center text-gray-500"><?= number_format($b['sale_count']) ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-green-700"><?= formatMoney((float)$b['total_revenue'], $b['currency']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex flex-col items-center gap-1">
                            <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $b['is_active']?'bg-green-100 text-green-700':'bg-red-100 text-red-700' ?>">
                                <?= $b['is_active']?'Active':'Inactive' ?>
                            </span>
                            <span class="px-1.5 py-0.5 text-[10px] font-medium rounded-full <?= ($b['business_type']??'products')==='services'?'bg-purple-100 text-purple-700':'bg-blue-100 text-blue-700' ?>">
                                <?= ($b['business_type']??'products')==='services'?'Services':'Products' ?>
                            </span>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex justify-center gap-1">
                            <a href="businesses.php?action=edit&id=<?= $b['id'] ?>" class="text-blue-600 p-1 hover:text-blue-800" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            <a href="businesses.php?action=toggle&id=<?= $b['id'] ?>&csrf=<?= csrfToken() ?>"
                               class="<?= $b['is_active']?'text-yellow-500 hover:text-yellow-700':'text-green-500 hover:text-green-700' ?> p-1"
                               title="<?= $b['is_active']?'Deactivate':'Activate' ?>"
                               onclick="return confirm('<?= $b['is_active']?'Deactivate':'Activate' ?> this business?')">
                                <i class="fa-solid fa-<?= $b['is_active']?'toggle-off':'toggle-on' ?>"></i>
                            </a>
                            <a href="businesses.php?action=delete&id=<?= $b['id'] ?>&csrf=<?= csrfToken() ?>"
                               class="text-red-500 p-1 hover:text-red-700" title="Delete"
                               onclick="return confirm('Delete business <?= h(addslashes($b['name'])) ?>? This cannot be undone.')">
                                <i class="fa-solid fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag, 'businesses.php?' . http_build_query(array_filter(compact('search'))) . '&') ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
