<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('inventory');
if (isAdmin()) { redirect(url('admin/businesses')); }

$db    = getDB();
$bizId = currentBusinessId();
$errors = [];

// Ensure table exists
$db->exec("CREATE TABLE IF NOT EXISTS `services` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `price_type` ENUM('fixed','hourly','custom') NOT NULL DEFAULT 'fixed',
    `duration_minutes` INT UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`), KEY `idx_svc_biz` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$catsQ = $db->prepare('SELECT id, name FROM categories WHERE business_id=? ORDER BY name');
$catsQ->execute([$bizId]);
$categories = $catsQ->fetchAll();

if (isPost()) {
    verifyCsrf();
    $name      = trim(post('name'));
    $catId     = (int)post('category_id') ?: null;
    $desc      = trim(post('description'));
    $price     = max(0, (float)post('price'));
    $priceType = in_array(post('price_type'), ['fixed','hourly','custom']) ? post('price_type') : 'fixed';
    $durH      = max(0, (int)post('duration_h'));
    $durM      = max(0, min(59, (int)post('duration_m')));
    $durMin    = ($durH * 60 + $durM) ?: null;
    $isActive  = post('is_active') === '1' ? 1 : 0;

    if (empty($name)) $errors[] = 'Service name is required.';
    if ($price <= 0 && $priceType !== 'custom') $errors[] = 'Price must be greater than 0.';

    if (empty($errors)) {
        $db->prepare('INSERT INTO services (business_id, category_id, name, description, price, price_type, duration_minutes, is_active) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$bizId, $catId, $name, $desc ?: null, $price, $priceType, $durMin, $isActive]);
        $newId = (int)$db->lastInsertId();
        auditLog('create', 'services', $newId, [], compact('name', 'price'));
        flash('success', "Service '{$name}' added successfully.");
        redirect(url('services'));
    }
}

$f = isPost() ? $_POST : ['name'=>'','price'=>'','price_type'=>'fixed','is_active'=>'1'];

$pageTitle = 'Add Service';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="max-w-2xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Add Service</h2>
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Service Name *</label>
                    <input type="text" name="name" value="<?= h($f['name'] ?? '') ?>" required autofocus
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                        placeholder="e.g. Haircut, Legal Consultation, Plumbing Repair">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <select name="category_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <option value="">-- None --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= ($f['category_id']??'')==$cat['id']?'selected':'' ?>><?= h($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price Type</label>
                    <select name="price_type" id="price_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none" onchange="togglePriceHelp(this.value)">
                        <option value="fixed"  <?= ($f['price_type']??'fixed')==='fixed'?'selected':'' ?>>Fixed Rate</option>
                        <option value="hourly" <?= ($f['price_type']??'fixed')==='hourly'?'selected':'' ?>>Hourly Rate</option>
                        <option value="custom" <?= ($f['price_type']??'fixed')==='custom'?'selected':'' ?>>Custom / Quote</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price (<?= currencySymbol() ?>)</label>
                    <input type="number" name="price" id="price_field" value="<?= h($f['price'] ?? '0') ?>" min="0" step="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    <p id="price_help" class="text-xs text-gray-400 mt-1 hidden">Set to 0 for custom-quoted services.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Duration <span class="text-gray-400 text-xs font-normal">(optional)</span></label>
                    <div class="flex gap-2">
                        <div class="flex-1">
                            <input type="number" name="duration_h" value="<?= h($f['duration_h'] ?? '0') ?>" min="0" max="999" placeholder="0"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            <p class="text-xs text-gray-400 mt-1 text-center">Hours</p>
                        </div>
                        <div class="flex-1">
                            <input type="number" name="duration_m" value="<?= h($f['duration_m'] ?? '0') ?>" min="0" max="59" placeholder="0"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            <p class="text-xs text-gray-400 mt-1 text-center">Minutes</p>
                        </div>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description <span class="text-gray-400 text-xs font-normal">(optional)</span></label>
                    <textarea name="description" rows="3"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"
                        placeholder="Describe what this service includes..."><?= h($f['description'] ?? '') ?></textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" <?= ($f['is_active']??'1')==='1'?'checked':'' ?> class="w-4 h-4 rounded text-blue-600">
                        <span class="text-sm font-medium text-gray-700">Service is active (visible in order forms)</span>
                    </label>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <i class="fa-solid fa-plus mr-1"></i> Add Service
                </button>
                <a href="index.php" class="bg-gray-100 text-gray-700 px-6 py-2 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
function togglePriceHelp(type) {
    document.getElementById('price_help').classList.toggle('hidden', type !== 'custom');
}
togglePriceHelp(document.getElementById('price_type').value);
</script>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
