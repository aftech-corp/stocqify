<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();
if (isAdmin()) { redirect(url('support/tickets')); }

$db    = getDB();
$bizId = currentBusinessId();
$me    = currentUser();

// Auto-create support tables if needed
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
    KEY `fk_sr_ticket` (`ticket_id`),
    CONSTRAINT `fk_sr_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$errors = [];

// ── Submit new ticket ──────────────────────────────────────
if (isPost() && post('_action') === 'new') {
    verifyCsrf();
    $subject  = post('subject');
    $message  = post('message');
    $priority = in_array(post('priority'), ['low','medium','high','urgent']) ? post('priority') : 'medium';
    if (!$subject) $errors[] = 'Subject is required.';
    if (!$message) $errors[] = 'Message is required.';
    if (empty($errors)) {
        $db->prepare("INSERT INTO support_tickets (business_id, user_id, subject, message, priority) VALUES (?,?,?,?,?)")
           ->execute([$bizId, $me['id'], $subject, $message, $priority]);
        flash('success', 'Support request submitted. Our team will respond shortly.');
        redirect(url('support'));
    }
}

// Mark tickets with new admin replies as seen when landing here
$db->prepare("UPDATE support_tickets SET business_read=1 WHERE business_id=? AND business_read=0")
   ->execute([$bizId]);

// Load this business's tickets
$stmt = $db->prepare("SELECT t.*,
    (SELECT COUNT(*) FROM support_replies r WHERE r.ticket_id=t.id) AS reply_count,
    (SELECT COUNT(*) FROM support_replies r2 WHERE r2.ticket_id=t.id AND r2.is_admin_reply=1) AS admin_replies
    FROM support_tickets t
    WHERE t.business_id=?
    ORDER BY t.updated_at DESC");
$stmt->execute([$bizId]);
$tickets = $stmt->fetchAll();

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

$pageTitle = 'Support';
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-xl font-bold text-gray-800">Support</h2>
        <p class="text-sm text-gray-500"><?= count($tickets) ?> ticket<?= count($tickets) != 1 ? 's' : '' ?> total</p>
    </div>
    <button onclick="document.getElementById('newTicketPanel').classList.toggle('hidden')"
            class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
        <i class="fa-solid fa-plus mr-1"></i> New Request
    </button>
</div>

<!-- New Ticket Form -->
<div id="newTicketPanel" class="<?= !empty($errors) ? '' : 'hidden' ?> mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h3 class="font-semibold text-gray-800 mb-4">Submit Support Request</h3>
        <?php if (!empty($errors)): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4">
            <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="_action" value="new">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subject *</label>
                    <input type="text" name="subject" placeholder="Brief description of your issue" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                    <select name="priority" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Message *</label>
                <textarea name="message" rows="4" required placeholder="Describe your issue or request in detail..."
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <i class="fa-solid fa-paper-plane mr-1"></i> Submit Request
                </button>
                <button type="button" onclick="document.getElementById('newTicketPanel').classList.add('hidden')"
                        class="bg-gray-100 text-gray-700 px-5 py-2 rounded-lg text-sm hover:bg-gray-200">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Tickets List -->
<?php if (empty($tickets)): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-16 text-center">
    <div class="w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-4">
        <i class="fa-solid fa-headset text-blue-400 text-3xl"></i>
    </div>
    <h3 class="text-xl font-bold text-gray-800 mb-2">No support requests yet</h3>
    <p class="text-gray-500 text-sm mb-4">Click "New Request" to contact our support team.</p>
</div>
<?php else: ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
    <div class="table-responsive">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-medium text-left">Subject</th>
                    <th class="px-4 py-3 font-medium text-center">Priority</th>
                    <th class="px-4 py-3 font-medium text-center">Status</th>
                    <th class="px-4 py-3 font-medium text-center">Replies</th>
                    <th class="px-4 py-3 font-medium text-left">Last Updated</th>
                    <th class="px-4 py-3 font-medium text-center">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y">
            <?php foreach ($tickets as $t): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                    <p class="font-medium text-gray-800"><?= h($t['subject']) ?></p>
                    <p class="text-xs text-gray-400 mt-0.5"><?= formatDateTime($t['created_at']) ?></p>
                </td>
                <td class="px-4 py-3 text-center">
                    <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $priorityColors[$t['priority']] ?? '' ?>">
                        <?= ucfirst($t['priority']) ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-center">
                    <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $statusColors[$t['status']] ?? '' ?>">
                        <?= ucwords(str_replace('_',' ',$t['status'])) ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-center">
                    <?php if ($t['reply_count'] > 0): ?>
                    <span class="text-xs font-semibold <?= $t['admin_replies'] > 0 ? 'text-green-600' : 'text-gray-500' ?>">
                        <?= $t['reply_count'] ?> <?= $t['admin_replies'] > 0 ? '(admin replied)' : '' ?>
                    </span>
                    <?php else: ?>
                    <span class="text-xs text-gray-400">None yet</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-xs text-gray-500"><?= formatDateTime($t['updated_at']) ?></td>
                <td class="px-4 py-3 text-center">
                    <a href="view.php?id=<?= $t['id'] ?>" class="text-blue-600 hover:text-blue-800 text-xs font-medium">View →</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
