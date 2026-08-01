<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();
if (!isAdmin()) { redirect(url('support')); }

$db = getDB();

// Auto-create tables if needed
$db->exec("CREATE TABLE IF NOT EXISTS `support_tickets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `status` ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    `admin_read` TINYINT(1) NOT NULL DEFAULT 0,
    `business_read` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS `support_replies` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `message` TEXT NOT NULL,
    `is_admin_reply` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `fk_sr2_ticket` (`ticket_id`),
    CONSTRAINT `fk_sr2_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$statusFilter   = get('status');
$priorityFilter = get('priority');
$page = max(1, (int)get('page', 1));

$where  = '1=1';
$params = [];
if ($statusFilter)   { $where .= ' AND t.status=?';   $params[] = $statusFilter; }
if ($priorityFilter) { $where .= ' AND t.priority=?'; $params[] = $priorityFilter; }

$totalQ = $db->prepare("SELECT COUNT(*) FROM support_tickets t WHERE $where");
$totalQ->execute($params);
$total = (int)$totalQ->fetchColumn();
$pag   = paginate($total, $page);

$stmt = $db->prepare("SELECT t.*, b.name AS biz_name, u.name AS submitter_name,
    (SELECT COUNT(*) FROM support_replies r WHERE r.ticket_id=t.id) AS reply_count
    FROM support_tickets t
    LEFT JOIN businesses b ON b.id = t.business_id
    LEFT JOIN users u ON u.id = t.user_id
    WHERE $where
    ORDER BY t.admin_read ASC, t.updated_at DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// Stats
$unread   = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE admin_read=0")->fetchColumn();
$open     = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status='open'")->fetchColumn();
$inProg   = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status='in_progress'")->fetchColumn();
$resolved = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status='resolved'")->fetchColumn();

$priorityColors = [
    'low'    => 'bg-gray-100 text-gray-600',
    'medium' => 'bg-blue-100 text-blue-700',
    'high'   => 'bg-orange-100 text-orange-700',
    'urgent' => 'bg-red-100 text-red-700',
];
$statusColors = [
    'open'        => 'bg-blue-100 text-blue-700',
    'in_progress' => 'bg-amber-100 text-amber-700',
    'resolved'    => 'bg-green-100 text-green-700',
    'closed'      => 'bg-gray-100 text-gray-500',
];

$pageTitle = 'Support Tickets';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Support Tickets</h2>
        <p class="text-sm text-gray-500"><?= number_format($total) ?> ticket<?= $total != 1 ? 's' : '' ?>
            <?php if ($unread > 0): ?>
            · <span class="text-red-600 font-semibold"><?= $unread ?> unread</span>
            <?php endif; ?>
        </p>
    </div>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-red-50 rounded-xl p-4 text-center">
        <p class="text-2xl font-black text-red-700"><?= $unread ?></p>
        <p class="text-xs text-red-600">Unread</p>
    </div>
    <div class="bg-blue-50 rounded-xl p-4 text-center">
        <p class="text-2xl font-black text-blue-700"><?= $open ?></p>
        <p class="text-xs text-blue-600">Open</p>
    </div>
    <div class="bg-amber-50 rounded-xl p-4 text-center">
        <p class="text-2xl font-black text-amber-700"><?= $inProg ?></p>
        <p class="text-xs text-amber-600">In Progress</p>
    </div>
    <div class="bg-green-50 rounded-xl p-4 text-center">
        <p class="text-2xl font-black text-green-700"><?= $resolved ?></p>
        <p class="text-xs text-green-600">Resolved</p>
    </div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3">
        <select name="status" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Statuses</option>
            <option value="open"        <?= $statusFilter==='open'        ?'selected':'' ?>>Open</option>
            <option value="in_progress" <?= $statusFilter==='in_progress' ?'selected':'' ?>>In Progress</option>
            <option value="resolved"    <?= $statusFilter==='resolved'    ?'selected':'' ?>>Resolved</option>
            <option value="closed"      <?= $statusFilter==='closed'      ?'selected':'' ?>>Closed</option>
        </select>
        <select name="priority" class="border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
            <option value="">All Priorities</option>
            <option value="urgent"  <?= $priorityFilter==='urgent'  ?'selected':'' ?>>Urgent</option>
            <option value="high"    <?= $priorityFilter==='high'    ?'selected':'' ?>>High</option>
            <option value="medium"  <?= $priorityFilter==='medium'  ?'selected':'' ?>>Medium</option>
            <option value="low"     <?= $priorityFilter==='low'     ?'selected':'' ?>>Low</option>
        </select>
        <button class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">Filter</button>
        <a href="tickets.php" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">Clear</a>
    </form>
</div>

<!-- Tickets Table -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">#</th>
                    <th class="px-4 py-3 font-medium text-left">Subject</th>
                    <th class="px-4 py-3 font-medium text-left">Business</th>
                    <th class="px-4 py-3 font-medium text-center">Priority</th>
                    <th class="px-4 py-3 font-medium text-center">Status</th>
                    <th class="px-4 py-3 font-medium text-center">Replies</th>
                    <th class="px-4 py-3 font-medium text-left">Updated</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($tickets)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No tickets found.</td></tr>
                <?php else: ?>
                <?php foreach ($tickets as $t): ?>
                <tr class="hover:bg-gray-50 <?= !$t['admin_read'] ? 'bg-blue-50/40' : '' ?>">
                    <td class="px-4 py-3 text-gray-400 font-mono text-xs">#<?= $t['id'] ?></td>
                    <td class="px-4 py-3">
                        <a href="view.php?id=<?= $t['id'] ?>" class="font-medium text-blue-700 hover:underline">
                            <?php if (!$t['admin_read']): ?>
                            <span class="inline-block w-2 h-2 rounded-full bg-red-500 mr-1 align-middle"></span>
                            <?php endif; ?>
                            <?= h($t['subject']) ?>
                        </a>
                        <p class="text-xs text-gray-400 mt-0.5">by <?= h($t['submitter_name'] ?? '—') ?></p>
                    </td>
                    <td class="px-4 py-3 text-gray-600 text-xs"><?= h($t['biz_name'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $priorityColors[$t['priority']] ?>">
                            <?= ucfirst($t['priority']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $statusColors[$t['status']] ?>">
                            <?= ucwords(str_replace('_',' ',$t['status'])) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-500"><?= $t['reply_count'] ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= formatDateTime($t['updated_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t">
        <?= renderPagination($pag, 'tickets.php?' . http_build_query(array_filter(compact('statusFilter','priorityFilter'))) . '&') ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
