<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
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

// Ensure subscriptions table exists
$db->exec("CREATE TABLE IF NOT EXISTS `business_subscriptions` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `plan`        ENUM('free','starter','professional','enterprise') NOT NULL DEFAULT 'free',
    `status`      ENUM('trial','active','expired','suspended','cancelled') NOT NULL DEFAULT 'trial',
    `starts_at`   DATE NOT NULL,
    `expires_at`  DATE DEFAULT NULL,
    `notes`       TEXT DEFAULT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `biz_id` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────
function _planColor(string $plan): string {
    return match($plan) {
        'starter'      => 'bg-blue-100 text-blue-700',
        'professional' => 'bg-purple-100 text-purple-700',
        'enterprise'   => 'bg-amber-100 text-amber-700',
        default        => 'bg-gray-100 text-gray-600',
    };
}
function _statusColor(string $status): string {
    return match($status) {
        'trial'     => 'bg-sky-100 text-sky-700',
        'active'    => 'bg-green-100 text-green-700',
        'expired'   => 'bg-red-100 text-red-700',
        'suspended' => 'bg-orange-100 text-orange-700',
        'cancelled' => 'bg-gray-100 text-gray-500',
        default     => 'bg-gray-100 text-gray-500',
    };
}

// Current subscription for a business (latest row)
function _bizSub(PDO $db, int $bizId): array {
    $s = $db->prepare("SELECT * FROM business_subscriptions WHERE business_id=? ORDER BY id DESC LIMIT 1");
    $s->execute([$bizId]);
    return $s->fetch() ?: [];
}

// ─────────────────────────────────────────────────────────────
// SAVE / UPDATE
// ─────────────────────────────────────────────────────────────
if (isPost() && $bizId) {
    verifyCsrf();
    $plan      = in_array(post('plan'), ['free','starter','professional','enterprise']) ? post('plan') : 'free';
    $status    = in_array(post('status'), ['trial','active','expired','suspended','cancelled']) ? post('status') : 'trial';
    $startsAt  = post('starts_at') ?: date('Y-m-d');
    $expiresAt = post('expires_at') ?: null;
    $notes     = trim(post('notes'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsAt)) $errors[] = 'Invalid start date.';
    if ($expiresAt && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) $errors[] = 'Invalid expiry date.';

    if (empty($errors)) {
        $existing = _bizSub($db, $bizId);
        if ($existing) {
            $db->prepare("UPDATE business_subscriptions SET plan=?, status=?, starts_at=?, expires_at=?, notes=? WHERE id=?")
               ->execute([$plan, $status, $startsAt, $expiresAt, $notes, $existing['id']]);
        } else {
            $db->prepare("INSERT INTO business_subscriptions (business_id, plan, status, starts_at, expires_at, notes) VALUES (?,?,?,?,?,?)")
               ->execute([$bizId, $plan, $status, $startsAt, $expiresAt, $notes]);
        }
        flash('success', 'Subscription updated successfully.');
        redirect(url('admin/subscriptions'));
    }
    $action = 'edit';
}

// ─────────────────────────────────────────────────────────────
// LIST
// ─────────────────────────────────────────────────────────────
if ($action === 'list') {
    // ── Filters & pagination ──────────────────────────────────
    $search       = trim(get('q', ''));
    $filterPlan   = get('plan',   '');
    $filterStatus = get('status', '');
    $perPage      = 15;
    $page         = max(1, (int)get('page', 1));
    $offset       = ($page - 1) * $perPage;

    $validPlans    = ['free','starter','professional','enterprise'];
    $validStatuses = ['trial','active','expired','suspended','cancelled'];
    if (!in_array($filterPlan, $validPlans))       $filterPlan   = '';
    if (!in_array($filterStatus, $validStatuses))  $filterStatus = '';

    // Build WHERE for search / filters
    $whereParts  = [];
    $whereParams = [];

    if ($search !== '') {
        $whereParts[]  = 'b.name LIKE ?';
        $whereParams[] = '%' . $search . '%';
    }
    if ($filterPlan !== '') {
        $whereParts[]  = 'COALESCE(bs.plan, \'free\') = ?';
        $whereParams[] = $filterPlan;
    }
    if ($filterStatus !== '') {
        $whereParts[]  = 'COALESCE(bs.status, \'trial\') = ?';
        $whereParams[] = $filterStatus;
    }

    $whereSQL = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

    $baseFrom = "FROM businesses b
        LEFT JOIN business_subscriptions bs ON bs.id = (
            SELECT id FROM business_subscriptions WHERE business_id = b.id ORDER BY id DESC LIMIT 1
        )";

    // Always-on summary counts (ignores filters)
    $allRows     = $db->query("SELECT COALESCE(bs.status,'trial') AS st {$baseFrom}")->fetchAll(PDO::FETCH_COLUMN, 0);
    $totalBiz    = count($allRows);
    $activeSubs  = count(array_filter($allRows, fn($s) => $s === 'active'));
    $trialSubs   = count(array_filter($allRows, fn($s) => $s === 'trial'));
    $expiredSubs = count(array_filter($allRows, fn($s) => in_array($s, ['expired','suspended','cancelled'])));

    // Total matching rows for pagination
    $countStmt = $db->prepare("SELECT COUNT(*) {$baseFrom} {$whereSQL}");
    $countStmt->execute($whereParams);
    $totalRows  = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalRows / $perPage);
    $page       = min($page, max(1, $totalPages));

    // Paginated list
    $listStmt = $db->prepare("SELECT b.id, b.name, b.is_active, b.business_type,
        bs.plan, bs.status, bs.starts_at, bs.expires_at
        {$baseFrom} {$whereSQL}
        ORDER BY b.name ASC
        LIMIT {$perPage} OFFSET {$offset}");
    $listStmt->execute($whereParams);
    $businesses = $listStmt->fetchAll();

    // URL builder preserving filters
    $filterBase = http_build_query(array_filter([
        'q'      => $search,
        'plan'   => $filterPlan,
        'status' => $filterStatus,
    ]));
    $pageUrl = fn(int $p) => 'subscriptions.php?' . ($filterBase ? $filterBase . '&' : '') . 'page=' . $p;

    $pageTitle = 'Subscriptions';
    include __DIR__ . '/../../app/includes/header.php';
    include __DIR__ . '/../../app/includes/sidebar.php';
    ?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Subscriptions</h2>
        <p class="text-sm text-gray-500">Manage subscription plans for each business</p>
    </div>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-xs text-gray-500 uppercase font-semibold tracking-wide">Total Businesses</p>
        <p class="text-2xl font-bold text-gray-800 mt-1"><?= $totalBiz ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-xs text-green-600 uppercase font-semibold tracking-wide">Active</p>
        <p class="text-2xl font-bold text-green-700 mt-1"><?= $activeSubs ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-xs text-sky-600 uppercase font-semibold tracking-wide">Trial</p>
        <p class="text-2xl font-bold text-sky-700 mt-1"><?= $trialSubs ?></p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-xs text-red-500 uppercase font-semibold tracking-wide">Expired / Suspended</p>
        <p class="text-2xl font-bold text-red-600 mt-1"><?= $expiredSubs ?></p>
    </div>
</div>

<!-- Filter Bar -->
<form method="GET" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <input type="hidden" name="action" value="list">
    <div class="flex flex-wrap items-center gap-3">
        <!-- Search -->
        <div class="relative flex-1 min-w-48">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
            <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search business name…"
                   class="w-full pl-8 pr-3 py-2 text-sm border border-gray-200 rounded-lg outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-400">
        </div>
        <!-- Plan filter -->
        <select name="plan" class="border border-gray-200 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500 bg-white">
            <option value="">All Plans</option>
            <?php foreach (['free'=>'Free','starter'=>'Starter','professional'=>'Professional','enterprise'=>'Enterprise'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= $filterPlan === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
        <!-- Status filter -->
        <select name="status" class="border border-gray-200 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500 bg-white">
            <option value="">All Statuses</option>
            <?php foreach (['trial'=>'Trial','active'=>'Active','expired'=>'Expired','suspended'=>'Suspended','cancelled'=>'Cancelled'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= $filterStatus === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
            <i class="fa-solid fa-filter mr-1"></i> Filter
        </button>
        <?php if ($search || $filterPlan || $filterStatus): ?>
        <a href="subscriptions.php" class="text-sm text-gray-500 hover:text-gray-700 px-2 py-2">
            <i class="fa-solid fa-xmark mr-1"></i> Clear
        </a>
        <?php endif; ?>
        <span class="ml-auto text-xs text-gray-400"><?= $totalRows ?> result<?= $totalRows !== 1 ? 's' : '' ?></span>
    </div>
</form>

<!-- Businesses Table -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Business</th>
                    <th class="px-4 py-3 font-medium text-center">Plan</th>
                    <th class="px-4 py-3 font-medium text-center">Status</th>
                    <th class="px-4 py-3 font-medium text-left">Start Date</th>
                    <th class="px-4 py-3 font-medium text-left">Expiry</th>
                    <th class="px-4 py-3 font-medium text-center">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y">
            <?php foreach ($businesses as $biz):
                $plan      = $biz['plan']    ?? 'free';
                $subStatus = $biz['status']  ?? 'trial';
                $expires   = $biz['expires_at'];
                $isExpired = $expires && $expires < date('Y-m-d') && $subStatus === 'active';
            ?>
            <tr class="hover:bg-gray-50 <?= !$biz['is_active'] ? 'opacity-50' : '' ?>">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-building text-blue-500 text-xs"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?= h($biz['name']) ?></p>
                            <p class="text-xs text-gray-400"><?= ucfirst($biz['business_type'] ?? 'products') ?> business<?= !$biz['is_active'] ? ' · Inactive' : '' ?></p>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3 text-center">
                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= _planColor($plan) ?>">
                        <?= ucfirst($plan) ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-center">
                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $isExpired ? 'bg-red-100 text-red-700' : _statusColor($subStatus) ?>">
                        <?= $isExpired ? 'Overdue' : ucfirst($subStatus) ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-xs text-gray-500">
                    <?= $biz['starts_at'] ? date('d M Y', strtotime($biz['starts_at'])) : '—' ?>
                </td>
                <td class="px-4 py-3 text-xs <?= $isExpired ? 'text-red-600 font-semibold' : 'text-gray-500' ?>">
                    <?php if ($expires): ?>
                    <?= date('d M Y', strtotime($expires)) ?>
                    <?php if ($expires >= date('Y-m-d') && $subStatus === 'active'): ?>
                    <?php $daysLeft = (int)ceil((strtotime($expires) - time()) / 86400); ?>
                    <span class="text-gray-400 ml-1">(<?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> left)</span>
                    <?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="px-4 py-3 text-center">
                    <a href="subscriptions.php?action=edit&id=<?= $biz['id'] ?>"
                       class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-800 px-2 py-1 rounded hover:bg-blue-50">
                        <i class="fa-solid fa-pen-to-square"></i> Manage
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($businesses)): ?>
            <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">No businesses found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100">
        <p class="text-xs text-gray-500">
            Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> of <?= $totalRows ?>
        </p>
        <div class="flex items-center gap-1">
            <?php if ($page > 1): ?>
            <a href="<?= $pageUrl($page - 1) ?>" class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50">
                <i class="fa-solid fa-chevron-left"></i>
            </a>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            if ($start > 1): ?>
            <a href="<?= $pageUrl(1) ?>" class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50">1</a>
            <?php if ($start > 2): ?><span class="text-xs text-gray-400 px-1">…</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $start; $p <= $end; $p++): ?>
            <a href="<?= $pageUrl($p) ?>"
               class="px-3 py-1.5 text-xs rounded-lg border <?= $p === $page ? 'bg-blue-600 border-blue-600 text-white font-semibold' : 'border-gray-200 text-gray-600 hover:bg-gray-50' ?>">
                <?= $p ?>
            </a>
            <?php endfor; ?>

            <?php if ($end < $totalPages): ?>
            <?php if ($end < $totalPages - 1): ?><span class="text-xs text-gray-400 px-1">…</span><?php endif; ?>
            <a href="<?= $pageUrl($totalPages) ?>" class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50"><?= $totalPages ?></a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
            <a href="<?= $pageUrl($page + 1) ?>" class="px-3 py-1.5 text-xs rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50">
                <i class="fa-solid fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

    <?php include __DIR__ . '/../../app/includes/footer.php';
    exit;
}

// ─────────────────────────────────────────────────────────────
// EDIT
// ─────────────────────────────────────────────────────────────
if ($action === 'edit' && $bizId) {
    $biz = $db->prepare("SELECT * FROM businesses WHERE id=? LIMIT 1");
    $biz->execute([$bizId]);
    $biz = $biz->fetch();
    if (!$biz) { flash('error', 'Business not found.'); redirect(url('admin/subscriptions')); }

    $sub = _bizSub($db, $bizId);

    // Load subscription history
    $history = $db->prepare("SELECT * FROM business_subscriptions WHERE business_id=? ORDER BY id DESC LIMIT 10");
    $history->execute([$bizId]);
    $history = $history->fetchAll();

    $plan      = post('plan')       ?? $sub['plan']       ?? 'free';
    $status    = post('status')     ?? $sub['status']     ?? 'trial';
    $startsAt  = post('starts_at')  ?? $sub['starts_at']  ?? date('Y-m-d');
    $expiresAt = post('expires_at') ?? $sub['expires_at'] ?? '';
    $notes     = post('notes')      ?? $sub['notes']      ?? '';

    $pageTitle = 'Subscription — ' . h($biz['name']);
    include __DIR__ . '/../../app/includes/header.php';
    include __DIR__ . '/../../app/includes/sidebar.php';
    ?>

<div class="flex items-center gap-3 mb-6">
    <a href="subscriptions.php" class="text-gray-500 hover:text-gray-700">
        <i class="fa-solid fa-arrow-left"></i>
    </a>
    <div>
        <h2 class="text-xl font-bold text-gray-800"><?= h($biz['name']) ?></h2>
        <p class="text-sm text-gray-400">Manage subscription plan</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Edit Form -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Subscription Details</h3>

            <?php if (!empty($errors)): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4">
                <?php foreach ($errors as $e): ?>
                <p class="text-red-700 text-sm"><?= h($e) ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Plan</label>
                        <select name="plan" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                            <?php foreach (['free'=>'Free','starter'=>'Starter','professional'=>'Professional','enterprise'=>'Enterprise'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= $plan === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                            <?php foreach (['trial'=>'Trial','active'=>'Active','expired'=>'Expired','suspended'=>'Suspended','cancelled'=>'Cancelled'] as $v => $l): ?>
                            <option value="<?= $v ?>" <?= $status === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                        <input type="date" name="starts_at" value="<?= h($startsAt) ?>"
                               class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Expiry Date <span class="text-gray-400 font-normal">(optional)</span></label>
                        <input type="date" name="expires_at" value="<?= h($expiresAt) ?>"
                               class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes <span class="text-gray-400 font-normal">(optional)</span></label>
                        <textarea name="notes" rows="3" placeholder="Internal notes about this subscription…"
                                  class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500 resize-none"><?= h($notes) ?></textarea>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                        <i class="fa-solid fa-save mr-1"></i> Save Subscription
                    </button>
                    <a href="subscriptions.php" class="bg-gray-100 text-gray-600 px-5 py-2 rounded-lg text-sm hover:bg-gray-200">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Info Panel -->
    <div class="space-y-4">
        <!-- Plan overview -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h4 class="font-semibold text-gray-700 text-sm mb-3">Plan Overview</h4>
            <div class="space-y-3 text-sm">
                <?php foreach ([
                    'free'         => ['Free', 'Basic access, limited features', '#6b7280'],
                    'starter'      => ['Starter', 'Core features, up to 5 users', '#2563eb'],
                    'professional' => ['Professional', 'Full feature access, up to 20 users', '#7c3aed'],
                    'enterprise'   => ['Enterprise', 'Unlimited users, priority support, custom features', '#d97706'],
                ] as $pk => [$pl, $desc, $color]): ?>
                <div class="flex items-start gap-2.5 p-2.5 rounded-lg <?= $plan === $pk ? 'bg-blue-50 border border-blue-200' : 'opacity-50' ?>">
                    <div class="w-2 h-2 rounded-full mt-1.5 flex-shrink-0" style="background:<?= $color ?>"></div>
                    <div>
                        <p class="font-semibold text-gray-800"><?= $pl ?></p>
                        <p class="text-xs text-gray-500"><?= $desc ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- History -->
        <?php if (!empty($history) && count($history) > 1): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h4 class="font-semibold text-gray-700 text-sm mb-3">History</h4>
            <div class="space-y-2">
                <?php foreach (array_slice($history, 0, 5) as $i => $h): ?>
                <div class="flex items-center gap-2 text-xs <?= $i === 0 ? 'text-gray-700 font-medium' : 'text-gray-400' ?>">
                    <span class="px-1.5 py-0.5 rounded <?= _planColor($h['plan']) ?>"><?= ucfirst($h['plan']) ?></span>
                    <span class="px-1.5 py-0.5 rounded <?= _statusColor($h['status']) ?>"><?= ucfirst($h['status']) ?></span>
                    <span class="ml-auto"><?= date('d M Y', strtotime($h['updated_at'])) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

    <?php include __DIR__ . '/../../app/includes/footer.php';
    exit;
}

// Fallback — redirect to list
redirect(url('admin/subscriptions'));
