<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requirePermission('inventory');
if (isAdmin()) { redirect(url('admin/businesses')); }

$db    = getDB();
$bizId = currentBusinessId();
$id    = (int)get('id');
$errors = [];

$svcQ = $db->prepare('SELECT * FROM services WHERE id=? AND business_id=?');
$svcQ->execute([$id, $bizId]);
$service = $svcQ->fetch();
if (!$service) { flash('error', 'Service not found.'); redirect(url('services')); }

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

    if (empty($errors)) {
        $db->prepare('UPDATE services SET category_id=?, name=?, description=?, price=?, price_type=?, duration_minutes=?, is_active=?, updated_at=NOW() WHERE id=? AND business_id=?')
           ->execute([$catId, $name, $desc ?: null, $price, $priceType, $durMin, $isActive, $id, $bizId]);
        auditLog('update', 'services', $id, $service, compact('name', 'price'));
        flash('success', 'Service updated successfully.');
        redirect(url('services'));
    }
}

// Build duration fields from stored minutes
$durHExist = $service['duration_minutes'] ? intdiv($service['duration_minutes'], 60) : 0;
$durMExist = $service['duration_minutes'] ? $service['duration_minutes'] % 60 : 0;

$f = isPost() ? $_POST : array_merge($service, ['duration_h'=>$durHExist,'duration_m'=>$durMExist]);

$pageTitle = 'Edit Service';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="max-w-2xl mx-auto">
    <div class="flex items-center gap-3 mb-6">
        <a href="index.php" class="text-gray-500 hover:text-gray-700"><i class="fa-solid fa-arrow-left"></i></a>
        <h2 class="text-xl font-bold text-gray-800">Edit Service: <?= h($service['name']) ?></h2>
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
                    <input type="text" name="name" value="<?= h($f['name'] ?? '') ?>" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price (<?= CURRENCY_SYMBOL ?>)</label>
                    <input type="number" name="price" value="<?= h($f['price'] ?? '0') ?>" min="0" step="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    <p id="price_help" class="text-xs text-gray-400 mt-1 hidden">Set to 0 for custom-quoted services.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Duration <span class="text-gray-400 text-xs font-normal">(optional)</span></label>
                    <div class="flex gap-2">
                        <div class="flex-1">
                            <input type="number" name="duration_h" value="<?= h($f['duration_h'] ?? '0') ?>" min="0" max="999"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            <p class="text-xs text-gray-400 mt-1 text-center">Hours</p>
                        </div>
                        <div class="flex-1">
                            <input type="number" name="duration_m" value="<?= h($f['duration_m'] ?? '0') ?>" min="0" max="59"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                            <p class="text-xs text-gray-400 mt-1 text-center">Minutes</p>
                        </div>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description <span class="text-gray-400 text-xs font-normal">(optional)</span></label>
                    <textarea name="description" rows="3"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"><?= h($f['description'] ?? '') ?></textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" <?= ($f['is_active']??'1')==1?'checked':'' ?> class="w-4 h-4 rounded text-blue-600">
                        <span class="text-sm font-medium text-gray-700">Service is active</span>
                    </label>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <i class="fa-solid fa-save mr-1"></i> Save Changes
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
