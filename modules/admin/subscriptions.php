<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); include APP_PATH.'/includes/403.php'; exit; }

$db = getDB();

$db->exec("CREATE TABLE IF NOT EXISTS `subscription_plans` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `description` TEXT,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_popular` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` SMALLINT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS `subscription_plan_prices` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `plan_id` INT UNSIGNED NOT NULL,
    `currency_code` VARCHAR(10) NOT NULL,
    `monthly_price` DECIMAL(14,2) NOT NULL DEFAULT 0,
    `yearly_price` DECIMAL(14,2) NOT NULL DEFAULT 0,
    UNIQUE KEY `plan_currency` (`plan_id`,`currency_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS `subscription_plan_features` (
    `plan_id` INT UNSIGNED NOT NULL,
    `feature_key` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`plan_id`,`feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS `business_subscriptions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `business_id` INT UNSIGNED NOT NULL UNIQUE,
    `plan_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('active','trial','expired','cancelled') NOT NULL DEFAULT 'active',
    `billing_period` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    `currency_code` VARCHAR(10) NOT NULL DEFAULT 'NLE',
    `amount_paid` DECIMAL(14,2) DEFAULT 0,
    `payment_method` VARCHAR(50) DEFAULT NULL,
    `payment_reference` VARCHAR(200) DEFAULT NULL,
    `starts_at` DATE NOT NULL DEFAULT (CURRENT_DATE),
    `expires_at` DATE DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Migrate pre-existing table: add any missing columns (try/catch handles "already exists")
try { $db->exec("ALTER TABLE business_subscriptions ADD COLUMN `plan_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `business_id`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE business_subscriptions ADD COLUMN `billing_period` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly' AFTER `status`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE business_subscriptions ADD COLUMN `currency_code` VARCHAR(10) NOT NULL DEFAULT 'NLE' AFTER `billing_period`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE business_subscriptions ADD COLUMN `amount_paid` DECIMAL(14,2) DEFAULT 0 AFTER `currency_code`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE business_subscriptions ADD COLUMN `payment_method` VARCHAR(50) DEFAULT NULL AFTER `amount_paid`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE business_subscriptions ADD COLUMN `payment_reference` VARCHAR(200) DEFAULT NULL AFTER `payment_method`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE business_subscriptions ADD COLUMN `starts_at` DATE NOT NULL DEFAULT '2024-01-01' AFTER `payment_reference`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE business_subscriptions ADD COLUMN `expires_at` DATE DEFAULT NULL AFTER `starts_at`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE business_subscriptions ADD COLUMN `notes` TEXT DEFAULT NULL AFTER `expires_at`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE business_subscriptions ADD COLUMN `created_by` INT UNSIGNED DEFAULT NULL AFTER `notes`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE business_subscriptions ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`"); } catch (\Exception $__e) {}
// Remove duplicate rows — keep only the latest record per business before enforcing UNIQUE
try { $db->exec("DELETE bs1 FROM business_subscriptions bs1 INNER JOIN business_subscriptions bs2 ON bs1.business_id=bs2.business_id AND bs1.id < bs2.id"); } catch (\Exception $__e) {}
// Enforce one-subscription-per-business (try/catch handles already-exists)
try { $db->exec("ALTER TABLE business_subscriptions ADD UNIQUE INDEX `uidx_bsub_business` (`business_id`)"); } catch (\Exception $__e) {}
// Add plan quota columns to subscription_plans (try/catch handles already-exists)
try { $db->exec("ALTER TABLE subscription_plans ADD COLUMN `max_branches` INT UNSIGNED DEFAULT NULL AFTER `sort_order`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE subscription_plans ADD COLUMN `max_orders_per_month` INT UNSIGNED DEFAULT NULL AFTER `max_branches`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE subscription_plans ADD COLUMN `max_products` INT UNSIGNED DEFAULT NULL AFTER `max_orders_per_month`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE subscription_plans ADD COLUMN `max_customers` INT UNSIGNED DEFAULT NULL AFTER `max_products`"); } catch (\Exception $__e) {}
try { $db->exec("ALTER TABLE subscription_plans ADD COLUMN `max_users` INT UNSIGNED DEFAULT NULL AFTER `max_customers`"); } catch (\Exception $__e) {}

$allFeatureKeys = require APP_PATH . '/includes/feature_definitions.php';

// Auto-include any new feature keys into plans that already have features configured.
// This runs silently on every page load; INSERT IGNORE is a no-op when the row exists.
try {
    $__pwf = $db->query("SELECT DISTINCT plan_id FROM subscription_plan_features")->fetchAll(PDO::FETCH_COLUMN);
    if ($__pwf) {
        $__existQ = $db->query(
            "SELECT plan_id, feature_key FROM subscription_plan_features WHERE plan_id IN (" . implode(',', array_map('intval', $__pwf)) . ")"
        )->fetchAll();
        $__byPlan = [];
        foreach ($__existQ as $__r) $__byPlan[(int)$__r['plan_id']][$__r['feature_key']] = true;
        $__ins = $db->prepare("INSERT IGNORE INTO subscription_plan_features (plan_id, feature_key) VALUES (?,?)");
        foreach ($__pwf as $__pid) {
            foreach (array_keys($allFeatureKeys) as $__fk) {
                if (!isset($__byPlan[(int)$__pid][$__fk])) $__ins->execute([$__pid, $__fk]);
            }
        }
    }
    unset($__pwf, $__existQ, $__byPlan, $__ins, $__r, $__pid, $__fk);
} catch (\Exception $__e) {}

try {
    $__cr = $db->query("SELECT value FROM system_settings WHERE `key`='currencies'");
    $availCurrencies = $__cr ? (json_decode($__cr->fetchColumn() ?: '[]', true) ?: []) : [];
} catch (\Exception $__ce) { $availCurrencies = []; }
if (empty($availCurrencies)) {
    $availCurrencies = [
        ['code'=>'NLE','name'=>'Sierra Leone Leone (New)','symbol'=>'NLE'],
        ['code'=>'SLE','name'=>'Sierra Leone Leone','symbol'=>'Le'],
        ['code'=>'USD','name'=>'US Dollar','symbol'=>'$'],
        ['code'=>'GBP','name'=>'British Pound','symbol'=>'£'],
        ['code'=>'EUR','name'=>'Euro','symbol'=>'€'],
        ['code'=>'NGN','name'=>'Nigerian Naira','symbol'=>'₦'],
        ['code'=>'GHS','name'=>'Ghanaian Cedi','symbol'=>'₵'],
    ];
}

$view   = get('view', 'subscriptions');
$action = get('action', '');
$planId = (int)get('id', 0);
$bizId  = (int)get('biz', 0);
$errors = [];

// ── DELETE PLAN ───────────────────────────────────────────────────────────────
if ($action === 'plan_delete' && $planId) {
    verifyCsrf();
    $inUseQ = $db->prepare("SELECT COUNT(*) FROM business_subscriptions WHERE plan_id=? AND status IN ('active','trial')");
    $inUseQ->execute([$planId]);
    if ((int)$inUseQ->fetchColumn() > 0) {
        flash('error', 'Cannot delete a plan with active or trial subscriptions. Cancel them first.');
    } else {
        $db->prepare("DELETE FROM subscription_plans WHERE id=?")->execute([$planId]);
        flash('success', 'Plan deleted.');
    }
    redirect(url('admin/subscriptions') . '?view=plans');
}

// ── CANCEL SUBSCRIPTION ───────────────────────────────────────────────────────
if ($action === 'cancel' && $bizId) {
    verifyCsrf();
    $db->prepare("UPDATE business_subscriptions SET status='cancelled' WHERE business_id=?")->execute([$bizId]);
    flash('success', 'Subscription cancelled.');
    redirect(url('admin/subscriptions'));
}

// ── SAVE PLAN ─────────────────────────────────────────────────────────────────
if (isPost() && post('_action') === 'save_plan') {
    verifyCsrf();
    $pId        = (int)post('plan_id', 0);
    $pName      = post('name');
    $pDesc      = post('description', '');
    $pIsActive  = post('is_active') === '1' ? 1 : 0;
    $pIsPopular = post('is_popular') === '1' ? 1 : 0;
    $pSortOrder = (int)post('sort_order', 0);
    $pSlug      = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($pName)));
    $__nv = function(string $k): ?int { $v = trim(post($k,'')); return ($v !== '' && (int)$v > 0) ? (int)$v : null; };
    $pMaxBranches = $__nv('max_branches');
    $pMaxOrders   = $__nv('max_orders_per_month');
    $pMaxProducts = $__nv('max_products');
    $pMaxCustomers= $__nv('max_customers');
    $pMaxUsers    = $__nv('max_users');
    if (!$pName) $errors[] = 'Plan name is required.';
    if (empty($errors)) {
        if ($pId) {
            $db->prepare("UPDATE subscription_plans SET name=?,slug=?,description=?,is_active=?,is_popular=?,sort_order=?,max_branches=?,max_orders_per_month=?,max_products=?,max_customers=?,max_users=? WHERE id=?")
               ->execute([$pName,$pSlug,$pDesc,$pIsActive,$pIsPopular,$pSortOrder,$pMaxBranches,$pMaxOrders,$pMaxProducts,$pMaxCustomers,$pMaxUsers,$pId]);
        } else {
            $db->prepare("INSERT INTO subscription_plans (name,slug,description,is_active,is_popular,sort_order,max_branches,max_orders_per_month,max_products,max_customers,max_users) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$pName,$pSlug,$pDesc,$pIsActive,$pIsPopular,$pSortOrder,$pMaxBranches,$pMaxOrders,$pMaxProducts,$pMaxCustomers,$pMaxUsers]);
            $pId = (int)$db->lastInsertId();
        }
        $db->prepare("DELETE FROM subscription_plan_prices WHERE plan_id=?")->execute([$pId]);
        foreach ($availCurrencies as $curr) {
            $mo = (float)(post('price_monthly_'.$curr['code']) ?? 0);
            $yr = (float)(post('price_yearly_'.$curr['code']) ?? 0);
            if ($mo > 0 || $yr > 0)
                $db->prepare("INSERT INTO subscription_plan_prices (plan_id,currency_code,monthly_price,yearly_price) VALUES (?,?,?,?)")
                   ->execute([$pId,$curr['code'],$mo,$yr]);
        }
        $db->prepare("DELETE FROM subscription_plan_features WHERE plan_id=?")->execute([$pId]);
        $planFeatureKeys = [];
        $fStmt = $db->prepare("INSERT IGNORE INTO subscription_plan_features (plan_id,feature_key) VALUES (?,?)");
        foreach ($_POST['features'] ?? [] as $fKey) {
            if (isset($allFeatureKeys[$fKey])) {
                $fStmt->execute([$pId, $fKey]);
                $planFeatureKeys[] = $fKey;
            }
        }
        // Sync features for all businesses currently on this plan (active or trial)
        $bizOnPlanQ = $db->prepare(
            "SELECT business_id FROM business_subscriptions WHERE plan_id=? AND status IN ('active','trial')"
        );
        $bizOnPlanQ->execute([$pId]);
        $bizOnPlan = $bizOnPlanQ->fetchAll(PDO::FETCH_COLUMN);
        if ($bizOnPlan) {
            $syncStmt = $db->prepare(
                "INSERT INTO business_features (business_id,feature_key,status)
                 VALUES (?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)"
            );
            foreach ($bizOnPlan as $syncBizId) {
                foreach ($allFeatureKeys as $fk => $fm) {
                    $syncStmt->execute([
                        $syncBizId,
                        $fk,
                        in_array($fk, $planFeatureKeys) ? 'enabled' : 'disabled',
                    ]);
                }
            }
        }
        $syncedCount = count($bizOnPlan);
        $msg = 'Plan saved.' . ($syncedCount ? " Features synced for {$syncedCount} business" . ($syncedCount > 1 ? 'es' : '') . '.' : '');
        flash('success', $msg);
        redirect(url('admin/subscriptions').'?view=plans');
    }
    $view = 'plans'; $action = $pId ? 'edit' : 'add'; $planId = $pId;
}

// ── ASSIGN SUBSCRIPTION ───────────────────────────────────────────────────────
if (isPost() && post('_action') === 'assign_plan') {
    verifyCsrf();
    $aBizId  = (int)post('business_id');
    $aPlanId = (int)post('plan_id');
    $aPeriod = in_array(post('billing_period'),['monthly','yearly']) ? post('billing_period') : 'monthly';
    $aCurr   = post('currency_code','NLE');
    $aAmt    = (float)post('amount_paid',0);
    $aMeth   = post('payment_method','');
    $aRef    = post('payment_reference','');
    $aStat   = in_array(post('status'),['active','trial','expired','cancelled']) ? post('status') : 'active';
    $aStart  = post('starts_at', date('Y-m-d'));
    $aExp    = post('expires_at') ?: null;
    $aNotes  = post('notes','');
    $aApply  = (int)post('apply_features',0);
    if (!$aBizId || !$aPlanId) {
        $errors[] = 'Business and plan are required.';
    } else {
        // Delete then insert — works reliably regardless of UNIQUE index state
        $db->prepare("DELETE FROM business_subscriptions WHERE business_id=?")->execute([$aBizId]);
        $db->prepare("INSERT INTO business_subscriptions
            (business_id,plan_id,status,billing_period,currency_code,amount_paid,payment_method,payment_reference,starts_at,expires_at,notes,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$aBizId,$aPlanId,$aStat,$aPeriod,$aCurr,$aAmt,$aMeth?:null,$aRef?:null,$aStart,$aExp,$aNotes?:null,(int)currentUser()['id']]);
        if ($aApply) {
            $pfQ = $db->prepare("SELECT feature_key FROM subscription_plan_features WHERE plan_id=?");
            $pfQ->execute([$aPlanId]);
            $planFeats = $pfQ->fetchAll(PDO::FETCH_COLUMN);
            $fu = $db->prepare("INSERT INTO business_features (business_id,feature_key,status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)");
            foreach ($allFeatureKeys as $fk => $fm)
                $fu->execute([$aBizId,$fk,in_array($fk,$planFeats)?'enabled':'disabled']);
        }
        flash('success', 'Subscription assigned successfully.');
        redirect(url('admin/subscriptions'));
    }
    $action = 'assign'; $bizId = $aBizId;
}

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
$plans = $db->query("SELECT sp.*,
    (SELECT COUNT(*) FROM subscription_plan_features spf WHERE spf.plan_id=sp.id) AS feature_count,
    (SELECT COUNT(*) FROM business_subscriptions bs WHERE bs.plan_id=sp.id AND bs.status IN ('active','trial')) AS active_subs
    FROM subscription_plans sp ORDER BY sp.sort_order ASC, sp.id ASC")->fetchAll();

$bizWithSubs = $db->query("SELECT b.id, b.name, b.email, b.currency, b.is_active,
    bs.id AS sub_id, bs.plan_id, bs.status AS sub_status, bs.billing_period,
    bs.currency_code AS sub_currency, bs.amount_paid, bs.starts_at, bs.expires_at,
    sp.name AS plan_name, sp.is_popular
    FROM businesses b
    LEFT JOIN business_subscriptions bs ON bs.business_id=b.id
        AND bs.id = (SELECT MAX(bs2.id) FROM business_subscriptions bs2 WHERE bs2.business_id=b.id)
    LEFT JOIN subscription_plans sp ON sp.id=bs.plan_id
    ORDER BY (bs.id IS NULL) ASC, b.name ASC")->fetchAll();

$planPrices = [];
if ($plans) {
    $planIds = implode(',', array_map('intval', array_column($plans,'id')));
    $prQ = $db->query("SELECT * FROM subscription_plan_prices WHERE plan_id IN ($planIds)");
    foreach ($prQ->fetchAll() as $prRow)
        $planPrices[$prRow['plan_id']][$prRow['currency_code']] = $prRow;
}

$editPlan = null; $editPrices = []; $editFeatures = [];
if ($view === 'plans' && in_array($action,['add','edit'])) {
    if ($action === 'edit' && $planId) {
        $epQ = $db->prepare("SELECT * FROM subscription_plans WHERE id=?");
        $epQ->execute([$planId]);
        $editPlan = $epQ->fetch();
        if (!$editPlan) { flash('error','Plan not found.'); redirect(url('admin/subscriptions').'?view=plans'); }
        $prQ2 = $db->prepare("SELECT currency_code,monthly_price,yearly_price FROM subscription_plan_prices WHERE plan_id=?");
        $prQ2->execute([$planId]);
        foreach ($prQ2->fetchAll() as $prR) $editPrices[$prR['currency_code']] = $prR;
        $fkQ2 = $db->prepare("SELECT feature_key FROM subscription_plan_features WHERE plan_id=?");
        $fkQ2->execute([$planId]);
        $editFeatures = $fkQ2->fetchAll(PDO::FETCH_COLUMN);
    }
}

$assignBiz = null;
if ($action === 'assign' && $bizId) {
    $abQ = $db->prepare("SELECT b.*, b.currency AS biz_currency,
        bs.plan_id AS curr_plan_id, bs.status AS curr_status, bs.billing_period AS curr_period,
        bs.currency_code AS curr_currency, bs.expires_at AS curr_expires, bs.amount_paid AS curr_amount
        FROM businesses b LEFT JOIN business_subscriptions bs ON bs.business_id=b.id WHERE b.id=?");
    $abQ->execute([$bizId]);
    $assignBiz = $abQ->fetch();
    if (!$assignBiz) { flash('error','Business not found.'); redirect(url('admin/subscriptions')); }
}

$pageTitle = 'Subscriptions & Plans';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-5">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Subscriptions &amp; Plans</h2>
        <p class="text-sm text-gray-500">Configure plans and manage business subscriptions</p>
    </div>
    <?php if ($view === 'plans' && $action === ''): ?>
    <a href="subscriptions.php?view=plans&action=add"
       class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 flex items-center gap-2">
        <i class="fa-solid fa-plus"></i> Add Plan
    </a>
    <?php endif; ?>
</div>

<!-- Tab switcher -->
<?php if ($action === '' || ($view === 'plans' && in_array($action,['add','edit']))): ?>
<div class="flex gap-1 bg-gray-100 rounded-xl p-1 w-fit mb-6">
    <a href="subscriptions.php?view=subscriptions"
       class="px-5 py-2 text-sm font-semibold rounded-lg transition-colors <?= $view === 'subscriptions' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700' ?>">
        <i class="fa-solid fa-credit-card mr-1.5"></i> Business Subscriptions
    </a>
    <a href="subscriptions.php?view=plans"
       class="px-5 py-2 text-sm font-semibold rounded-lg transition-colors <?= $view === 'plans' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700' ?>">
        <i class="fa-solid fa-layer-group mr-1.5"></i> Plans
    </a>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
    <?php foreach ($errors as $err): ?><p class="text-red-700 text-sm"><?= h($err) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($view === 'plans' && in_array($action,['add','edit'])): ?>
<!-- ═══ PLAN FORM ════════════════════════════════════════════════════════════ -->
<a href="subscriptions.php?view=plans" class="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 mb-5 w-fit">
    <i class="fa-solid fa-arrow-left"></i> Back to Plans
</a>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="_action" value="save_plan">
    <input type="hidden" name="plan_id" value="<?= $planId ?>">

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <!-- Left: Info + Pricing -->
        <div class="xl:col-span-2 space-y-6">

            <!-- Basic Info -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-2 mb-4 pb-2 border-b border-gray-100">
                    <i class="fa-solid fa-layer-group text-gray-400 text-sm"></i>
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">Plan Details</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Plan Name *</label>
                        <input type="text" name="name" required
                            value="<?= h($editPlan['name'] ?? '') ?>"
                            placeholder="e.g. Basic, Standard, Premium"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea name="description" rows="2" placeholder="Brief description of this plan..."
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"><?= h($editPlan['description'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Display Order</label>
                        <input type="number" name="sort_order" value="<?= (int)($editPlan['sort_order'] ?? 0) ?>" min="0"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                        <p class="text-xs text-gray-400 mt-1">Lower = shown first</p>
                    </div>
                    <div class="flex flex-col justify-end gap-3 pb-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="is_active" value="1" class="w-4 h-4 rounded text-blue-600"
                                <?= ($editPlan['is_active'] ?? 1) ? 'checked' : '' ?>>
                            <span class="text-sm font-medium text-gray-700">Plan is Active</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="is_popular" value="1" class="w-4 h-4 rounded text-amber-500"
                                <?= ($editPlan['is_popular'] ?? 0) ? 'checked' : '' ?>>
                            <span class="text-sm font-medium text-gray-700">Mark as Popular</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Usage Limits -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-2 mb-1 pb-2 border-b border-gray-100">
                    <i class="fa-solid fa-gauge-high text-gray-400 text-sm"></i>
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">Usage Limits</span>
                    <span class="ml-auto text-xs text-gray-400">Leave blank for unlimited</span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-4 pt-3">
                    <?php
                    $__limits = [
                        'max_branches'         => ['label'=>'Max Branches',         'icon'=>'fa-code-branch'],
                        'max_orders_per_month' => ['label'=>'Orders / Month',        'icon'=>'fa-cart-shopping'],
                        'max_products'         => ['label'=>'Max Products',          'icon'=>'fa-boxes-stacked'],
                        'max_customers'        => ['label'=>'Max Customers',         'icon'=>'fa-users'],
                        'max_users'            => ['label'=>'Max Staff Users',       'icon'=>'fa-user-tie'],
                    ];
                    foreach ($__limits as $__lk => $__lm):
                        $__lv = $editPlan[$__lk] ?? null;
                    ?>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">
                            <i class="fa-solid <?= $__lm['icon'] ?> text-gray-400 mr-1"></i><?= $__lm['label'] ?>
                        </label>
                        <input type="number" name="<?= $__lk ?>"
                            value="<?= $__lv !== null ? (int)$__lv : '' ?>"
                            min="1" step="1" placeholder="Unlimited"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Pricing -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center gap-2 mb-4 pb-2 border-b border-gray-100">
                    <i class="fa-solid fa-coins text-gray-400 text-sm"></i>
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">Pricing Per Currency</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100">
                                <th class="text-left text-xs font-semibold text-gray-400 pb-2 pr-4 uppercase tracking-wider">Currency</th>
                                <th class="text-right text-xs font-semibold text-gray-400 pb-2 px-4 uppercase tracking-wider">Monthly</th>
                                <th class="text-right text-xs font-semibold text-gray-400 pb-2 pl-4 uppercase tracking-wider">Yearly</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                        <?php foreach ($availCurrencies as $curr):
                            $ep = $editPrices[$curr['code']] ?? null; ?>
                        <tr>
                            <td class="py-2.5 pr-4">
                                <span class="font-semibold text-gray-700"><?= h($curr['code']) ?></span>
                                <span class="text-xs text-gray-400 ml-1.5"><?= h($curr['name']) ?></span>
                            </td>
                            <td class="py-2.5 px-4">
                                <div class="flex items-center justify-end gap-1">
                                    <span class="text-gray-400 text-xs"><?= h($curr['symbol']) ?></span>
                                    <input type="number" name="price_monthly_<?= h($curr['code']) ?>"
                                        value="<?= $ep ? number_format((float)$ep['monthly_price'],2,'.','') : '' ?>"
                                        step="0.01" min="0" placeholder="0.00"
                                        class="w-28 px-2 py-1.5 border border-gray-200 rounded-lg text-sm text-right focus:ring-2 focus:ring-blue-500 outline-none">
                                </div>
                            </td>
                            <td class="py-2.5 pl-4">
                                <div class="flex items-center justify-end gap-1">
                                    <span class="text-gray-400 text-xs"><?= h($curr['symbol']) ?></span>
                                    <input type="number" name="price_yearly_<?= h($curr['code']) ?>"
                                        value="<?= $ep ? number_format((float)$ep['yearly_price'],2,'.','') : '' ?>"
                                        step="0.01" min="0" placeholder="0.00"
                                        class="w-28 px-2 py-1.5 border border-gray-200 rounded-lg text-sm text-right focus:ring-2 focus:ring-blue-500 outline-none">
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-gray-400 mt-3">Leave 0.00 for currencies not offered in this plan.</p>
            </div>
        </div>

        <!-- Right: Features -->
        <div class="xl:col-span-1">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 xl:sticky xl:top-6">
                <div class="flex items-center justify-between mb-3 pb-2 border-b border-gray-100">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">
                        <i class="fa-solid fa-toggle-on mr-1"></i> Included Features
                    </span>
                    <div class="flex gap-2">
                        <button type="button" onclick="document.querySelectorAll('[name=\'features[]\']').forEach(c=>c.checked=true)"
                            class="text-xs text-blue-600 hover:text-blue-800">All</button>
                        <span class="text-gray-300">|</span>
                        <button type="button" onclick="document.querySelectorAll('[name=\'features[]\']').forEach(c=>c.checked=false)"
                            class="text-xs text-gray-500 hover:text-gray-700">None</button>
                    </div>
                </div>
                <?php
                $sections = [];
                foreach ($allFeatureKeys as $fk => $fm) $sections[$fm['section']][$fk] = $fm;
                foreach ($sections as $sectionName => $sectionKeys):
                ?>
                <div class="mb-4">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5"><?= h($sectionName) ?></p>
                    <div class="space-y-1">
                    <?php foreach ($sectionKeys as $fk => $fm): ?>
                    <label class="flex items-center gap-2 cursor-pointer py-0.5 group">
                        <input type="checkbox" name="features[]" value="<?= h($fk) ?>"
                            class="w-3.5 h-3.5 rounded text-blue-600 flex-shrink-0"
                            <?= in_array($fk, $editFeatures) ? 'checked' : '' ?>>
                        <span class="text-xs text-gray-600 group-hover:text-gray-900 leading-tight"><?= h($fm['label']) ?></span>
                    </label>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="flex gap-3 mt-6">
        <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700">
            <i class="fa-solid fa-save mr-1"></i> <?= $action === 'edit' ? 'Save Changes' : 'Create Plan' ?>
        </button>
        <a href="subscriptions.php?view=plans" class="bg-gray-100 text-gray-700 px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
    </div>
</form>

<?php elseif ($view === 'plans'): ?>
<!-- ═══ PLANS LIST ═══════════════════════════════════════════════════════════ -->
<?php if (empty($plans)): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
    <div class="w-16 h-16 bg-blue-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
        <i class="fa-solid fa-layer-group text-2xl text-blue-400"></i>
    </div>
    <h3 class="font-semibold text-gray-700 mb-1">No plans yet</h3>
    <p class="text-sm text-gray-400 mb-4">Create your first subscription plan to get started.</p>
    <a href="subscriptions.php?view=plans&action=add"
       class="bg-blue-600 text-white px-5 py-2.5 rounded-lg text-sm font-medium hover:bg-blue-700 inline-block">
        <i class="fa-solid fa-plus mr-1"></i> Add Your First Plan
    </a>
</div>
<?php else: ?>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
<?php foreach ($plans as $plan):
    $pp = $planPrices[$plan['id']] ?? [];
    $primaryPrice = null; $primaryCurr = '';
    foreach (['NLE','USD','GBP','EUR','SLE'] as $pref) {
        if (isset($pp[$pref])) { $primaryPrice = $pp[$pref]; $primaryCurr = $pref; break; }
    }
    if (!$primaryPrice && !empty($pp)) {
        $primaryCurr  = array_key_first($pp);
        $primaryPrice = $pp[$primaryCurr];
    }
?>
<div class="bg-white rounded-xl shadow-sm border <?= $plan['is_popular'] ? 'border-amber-300 ring-1 ring-amber-200' : 'border-gray-100' ?> p-5 relative flex flex-col">
    <?php if ($plan['is_popular']): ?>
    <div class="absolute -top-3 left-5">
        <span class="bg-amber-400 text-amber-900 text-[11px] font-bold px-3 py-0.5 rounded-full shadow-sm">Popular</span>
    </div>
    <?php endif; ?>
    <div class="flex items-start justify-between mb-2">
        <h3 class="font-bold text-gray-800"><?= h($plan['name']) ?></h3>
        <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $plan['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
            <?= $plan['is_active'] ? 'Active' : 'Inactive' ?>
        </span>
    </div>
    <?php if ($plan['description']): ?>
    <p class="text-xs text-gray-400 mb-3 leading-snug"><?= h($plan['description']) ?></p>
    <?php endif; ?>
    <?php if ($primaryPrice): ?>
    <p class="text-xl font-black text-blue-600 mb-0.5">
        <?= h($primaryCurr) ?> <?= number_format((float)$primaryPrice['monthly_price'],2) ?>
        <span class="text-xs font-normal text-gray-400">/mo</span>
    </p>
    <?php if ((float)$primaryPrice['yearly_price'] > 0): ?>
    <p class="text-xs text-gray-400 mb-3"><?= h($primaryCurr) ?> <?= number_format((float)$primaryPrice['yearly_price'],2) ?>/yr</p>
    <?php else: ?><div class="mb-3"></div><?php endif; ?>
    <?php else: ?>
    <p class="text-sm text-gray-400 italic mb-3">No pricing configured</p>
    <?php endif; ?>
    <div class="flex items-center gap-4 text-xs text-gray-500 mb-3">
        <span><i class="fa-solid fa-toggle-on text-blue-400 mr-1"></i><?= (int)$plan['feature_count'] ?> features</span>
        <span><i class="fa-solid fa-building text-green-500 mr-1"></i><?= (int)$plan['active_subs'] ?> active</span>
        <?php if (count($pp) > 1): ?>
        <span><i class="fa-solid fa-coins text-amber-400 mr-1"></i><?= count($pp) ?> currencies</span>
        <?php endif; ?>
    </div>
    <?php
    $__limitsInfo = [];
    if (!empty($plan['max_branches']))         $__limitsInfo[] = '<i class="fa-solid fa-code-branch mr-1"></i>' . (int)$plan['max_branches'] . ' branches';
    if (!empty($plan['max_orders_per_month'])) $__limitsInfo[] = '<i class="fa-solid fa-cart-shopping mr-1"></i>' . (int)$plan['max_orders_per_month'] . ' orders/mo';
    if (!empty($plan['max_products']))         $__limitsInfo[] = '<i class="fa-solid fa-boxes-stacked mr-1"></i>' . (int)$plan['max_products'] . ' products';
    if (!empty($plan['max_customers']))        $__limitsInfo[] = '<i class="fa-solid fa-users mr-1"></i>' . (int)$plan['max_customers'] . ' customers';
    if (!empty($plan['max_users']))            $__limitsInfo[] = '<i class="fa-solid fa-user-tie mr-1"></i>' . (int)$plan['max_users'] . ' users';
    if ($__limitsInfo): ?>
    <div class="flex flex-wrap gap-2 text-[10px] text-amber-700 bg-amber-50 rounded-lg px-3 py-1.5 mb-3">
        <?= implode('<span class="text-amber-300 mx-0.5">·</span>', $__limitsInfo) ?>
    </div>
    <?php else: ?>
    <div class="text-[10px] text-gray-400 italic mb-3">No usage limits</div>
    <?php endif; ?>
    <div class="flex gap-2 pt-3 border-t border-gray-100 mt-auto">
        <a href="subscriptions.php?view=plans&action=edit&id=<?= $plan['id'] ?>"
           class="flex-1 text-center bg-blue-50 text-blue-700 px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-blue-100">
            <i class="fa-solid fa-pen mr-1"></i> Edit
        </a>
        <a href="subscriptions.php?view=plans&action=plan_delete&id=<?= $plan['id'] ?>&csrf_token=<?= csrfToken() ?>"
           class="text-red-500 px-3 py-1.5 rounded-lg text-xs font-medium hover:bg-red-50"
           onclick="return confirm('Delete plan \'<?= h(addslashes($plan['name'])) ?>\'? This cannot be undone.')">
            <i class="fa-solid fa-trash"></i>
        </a>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($view === 'subscriptions' && $action === 'assign' && $assignBiz): ?>
<!-- ═══ ASSIGN FORM ══════════════════════════════════════════════════════════ -->
<a href="subscriptions.php" class="flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 mb-5 w-fit">
    <i class="fa-solid fa-arrow-left"></i> Back to Subscriptions
</a>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 max-w-2xl">
    <div class="flex items-center gap-3 mb-5 pb-4 border-b border-gray-100">
        <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-bold flex-shrink-0">
            <?= strtoupper(substr($assignBiz['name'],0,1)) ?>
        </div>
        <div>
            <h3 class="font-bold text-gray-800"><?= h($assignBiz['name']) ?></h3>
            <p class="text-xs text-gray-400"><?= h($assignBiz['email'] ?? '') ?></p>
        </div>
    </div>

    <?php if ($assignBiz['curr_plan_id']): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-5 flex items-center gap-2 text-sm">
        <i class="fa-solid fa-circle-info text-blue-500 flex-shrink-0"></i>
        <span class="text-blue-700">This business already has a subscription — submitting will update it.</span>
    </div>
    <?php endif; ?>

    <?php if (empty($plans)): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-700">
        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
        No plans exist yet. <a href="subscriptions.php?view=plans&action=add" class="underline font-medium">Create a plan first</a>.
    </div>
    <?php else: ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="_action" value="assign_plan">
        <input type="hidden" name="business_id" value="<?= $bizId ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Plan *</label>
                <select name="plan_id" required id="assign-plan"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Select a plan --</option>
                    <?php foreach ($plans as $pl): ?>
                    <option value="<?= $pl['id'] ?>" <?= ($assignBiz['curr_plan_id']??0) == $pl['id'] ? 'selected' : '' ?>>
                        <?= h($pl['name']) ?><?= !$pl['is_active'] ? ' (Inactive)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Billing Period</label>
                <select name="billing_period" id="assign-period"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="monthly" <?= ($assignBiz['curr_period']??'monthly')==='monthly'?'selected':'' ?>>Monthly</option>
                    <option value="yearly"  <?= ($assignBiz['curr_period']??'')==='yearly'?'selected':'' ?>>Yearly</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="active"    <?= ($assignBiz['curr_status']??'active')==='active'   ?'selected':'' ?>>Active</option>
                    <option value="trial"     <?= ($assignBiz['curr_status']??'')==='trial'           ?'selected':'' ?>>Trial</option>
                    <option value="expired"   <?= ($assignBiz['curr_status']??'')==='expired'         ?'selected':'' ?>>Expired</option>
                    <option value="cancelled" <?= ($assignBiz['curr_status']??'')==='cancelled'       ?'selected':'' ?>>Cancelled</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
                <select name="currency_code" id="assign-currency"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                    <?php foreach ($availCurrencies as $curr): ?>
                    <option value="<?= h($curr['code']) ?>"
                        <?= ($assignBiz['curr_currency'] ?? $assignBiz['biz_currency'] ?? 'NLE') === $curr['code'] ? 'selected' : '' ?>>
                        <?= h($curr['code']) ?> — <?= h($curr['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Amount Paid</label>
                <input type="number" name="amount_paid" id="assign-amount"
                    value="<?= number_format((float)($assignBiz['curr_amount']??0),2,'.','') ?>"
                    step="0.01" min="0" placeholder="0.00"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                <select name="payment_method"
                    class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Select --</option>
                    <option value="cash">Cash</option>
                    <option value="orange_money">Orange Money</option>
                    <option value="afrimoney">Afrimoney</option>
                    <option value="qmoney">QMoney</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Reference</label>
                <input type="text" name="payment_reference" placeholder="e.g. OM123456789"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                <input type="date" name="starts_at" id="assign-start" value="<?= date('Y-m-d') ?>"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Expires</label>
                <input type="date" name="expires_at" id="assign-expires"
                    value="<?= h($assignBiz['curr_expires'] ?? '') ?>"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                <p class="text-xs text-gray-400 mt-0.5">Auto-calculated from start + period, or set manually.</p>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="2" placeholder="Payment notes, contact details..."
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"></textarea>
            </div>
            <div class="md:col-span-2">
                <label class="flex items-start gap-3 cursor-pointer bg-amber-50 border border-amber-200 rounded-xl p-4">
                    <input type="checkbox" name="apply_features" value="1" class="w-4 h-4 rounded text-amber-500 mt-0.5 flex-shrink-0">
                    <div>
                        <p class="text-sm font-semibold text-amber-800">Apply plan features to this business</p>
                        <p class="text-xs text-amber-600 mt-0.5 leading-relaxed">Enables all features included in this plan and disables all others. Overrides current feature settings for this business.</p>
                    </div>
                </label>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700">
                <i class="fa-solid fa-check mr-1"></i> Activate Subscription
            </button>
            <a href="subscriptions.php" class="bg-gray-100 text-gray-700 px-6 py-2.5 rounded-lg text-sm font-medium hover:bg-gray-200">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
(function(){
    var planSel   = document.getElementById('assign-plan');
    var periodSel = document.getElementById('assign-period');
    var currSel   = document.getElementById('assign-currency');
    var amtInp    = document.getElementById('assign-amount');
    var startInp  = document.getElementById('assign-start');
    var expInp    = document.getElementById('assign-expires');
    if (!planSel) return;

    var priceMap = <?= json_encode(
        array_reduce($plans, function($carry, $p) use ($planPrices) {
            $carry[$p['id']] = $planPrices[$p['id']] ?? [];
            return $carry;
        }, [])
    ) ?>;

    function updateAmount() {
        var pid = planSel.value, curr = currSel.value, per = periodSel.value;
        if (!pid || !priceMap[pid] || !priceMap[pid][curr]) return;
        var row = priceMap[pid][curr];
        amtInp.value = parseFloat(per === 'yearly' ? row.yearly_price : row.monthly_price).toFixed(2);
    }
    function updateExpiry() {
        if (!startInp.value) return;
        var d = new Date(startInp.value + 'T00:00:00');
        if (periodSel.value === 'yearly') d.setFullYear(d.getFullYear() + 1);
        else d.setMonth(d.getMonth() + 1);
        expInp.value = d.toISOString().split('T')[0];
    }
    planSel.addEventListener('change', updateAmount);
    currSel.addEventListener('change', updateAmount);
    periodSel.addEventListener('change', function(){ updateAmount(); updateExpiry(); });
    startInp.addEventListener('change', updateExpiry);
    updateExpiry();
})();
</script>

<?php else: ?>
<!-- ═══ BUSINESS SUBSCRIPTIONS LIST ═════════════════════════════════════════ -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500 border-b border-gray-100">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Business</th>
                    <th class="px-4 py-3 font-medium text-left">Plan</th>
                    <th class="px-4 py-3 font-medium text-center">Status</th>
                    <th class="px-4 py-3 font-medium text-left">Billing</th>
                    <th class="px-4 py-3 font-medium text-left">Expires</th>
                    <th class="px-4 py-3 font-medium text-right">Amount Paid</th>
                    <th class="px-4 py-3 font-medium text-center">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
            <?php if (empty($bizWithSubs)): ?>
            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No businesses registered yet.</td></tr>
            <?php else: ?>
            <?php foreach ($bizWithSubs as $biz):
                $today = date('Y-m-d');
                $isExpired = $biz['expires_at'] && $biz['expires_at'] < $today && $biz['sub_status'] === 'active';
                if ($biz['sub_status'] === 'active' && $isExpired) $stLabel = 'Expired';
                elseif ($biz['sub_status']) $stLabel = ucfirst($biz['sub_status']);
                else $stLabel = '';
                $stClass = '';
                if ($biz['sub_status'] === 'active' && !$isExpired) $stClass = 'bg-green-100 text-green-700';
                elseif ($biz['sub_status'] === 'trial') $stClass = 'bg-blue-100 text-blue-700';
                elseif ($biz['sub_status'] === 'cancelled') $stClass = 'bg-gray-100 text-gray-500';
                else $stClass = 'bg-red-100 text-red-700';
            ?>
            <tr class="hover:bg-gray-50 <?= $isExpired ? 'bg-red-50/30' : '' ?>">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-lg bg-blue-600 text-white flex items-center justify-center font-bold text-xs flex-shrink-0">
                            <?= strtoupper(substr($biz['name'],0,1)) ?>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-800"><?= h($biz['name']) ?></p>
                            <p class="text-xs text-gray-400"><?= h($biz['email'] ?? '') ?></p>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3">
                    <?php if ($biz['plan_name']): ?>
                    <span class="font-medium text-gray-700"><?= h($biz['plan_name']) ?></span>
                    <?php if ($biz['is_popular']): ?><span class="text-amber-400 text-xs ml-1">Popular</span><?php endif; ?>
                    <?php else: ?>
                    <span class="text-gray-400 text-xs italic">No plan</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-center">
                    <?php if ($stLabel): ?>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium <?= $stClass ?>"><?= $stLabel ?></span>
                    <?php else: ?><span class="text-gray-300 text-xs">—</span><?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-500 text-xs capitalize"><?= h($biz['billing_period'] ?? '—') ?></td>
                <td class="px-4 py-3 text-xs <?= $isExpired ? 'text-red-600 font-semibold' : 'text-gray-500' ?>">
                    <?= $biz['expires_at'] ? date('d M Y', strtotime($biz['expires_at'])) : '—' ?>
                </td>
                <td class="px-4 py-3 text-right text-sm font-medium text-gray-700">
                    <?php if ($biz['amount_paid']): ?>
                    <?= h($biz['sub_currency']) ?> <?= number_format((float)$biz['amount_paid'],2) ?>
                    <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
                </td>
                <td class="px-4 py-3">
                    <div class="flex justify-center gap-1">
                        <a href="subscriptions.php?action=assign&biz=<?= $biz['id'] ?>"
                           class="bg-blue-50 text-blue-700 px-2.5 py-1 rounded-lg text-xs font-medium hover:bg-blue-100">
                            <i class="fa-solid fa-<?= $biz['sub_id'] ? 'rotate' : 'plus' ?> mr-1"></i><?= $biz['sub_id'] ? 'Update' : 'Assign' ?>
                        </a>
                        <?php if ($biz['sub_id'] && !in_array($biz['sub_status'],['cancelled','expired'])): ?>
                        <a href="subscriptions.php?action=cancel&biz=<?= $biz['id'] ?>&csrf_token=<?= csrfToken() ?>"
                           class="text-red-400 px-2 py-1 rounded-lg text-xs hover:text-red-600 hover:bg-red-50"
                           onclick="return confirm('Cancel subscription for <?= h(addslashes($biz['name'])) ?>?')">
                            <i class="fa-solid fa-ban"></i>
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
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
