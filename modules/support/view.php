<?php
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
requireLogin();

$db    = getDB();
$me    = currentUser();
$id    = (int)get('id');

if (!$id) { flash('error', 'Invalid ticket.'); redirect(url('support')); }

// Load ticket — scope to own business unless admin
if (isAdmin()) {
    $stmt = $db->prepare("SELECT t.*, u.name AS submitter_name, b.name AS biz_name
        FROM support_tickets t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN businesses b ON b.id = t.business_id
        WHERE t.id=?");
    $stmt->execute([$id]);
} else {
    $stmt = $db->prepare("SELECT t.*, u.name AS submitter_name, b.name AS biz_name
        FROM support_tickets t
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN businesses b ON b.id = t.business_id
        WHERE t.id=? AND t.business_id=?");
    $stmt->execute([$id, currentBusinessId()]);
}
$ticket = $stmt->fetch();
if (!$ticket) { flash('error', 'Ticket not found.'); redirect(url('support')); }

// Mark as read
if (isAdmin() && !$ticket['admin_read']) {
    $db->prepare("UPDATE support_tickets SET admin_read=1 WHERE id=?")->execute([$id]);
}
if (!isAdmin() && !$ticket['business_read']) {
    $db->prepare("UPDATE support_tickets SET business_read=1 WHERE id=?")->execute([$id]);
}

$errors = [];

// ── Change status (admin only) ─────────────────────────────
if (isPost() && post('_action') === 'status') {
    verifyCsrf();
    $newStatus = post('status');
    if (in_array($newStatus, ['open','in_progress','resolved','closed'])) {
        $db->prepare("UPDATE support_tickets SET status=?, admin_read=1 WHERE id=?")->execute([$newStatus, $id]);
        flash('success', 'Status updated.');
        redirect(url('support/view') . "?id={$id}");
    }
}

// ── Reply ──────────────────────────────────────────────────
if (isPost() && post('_action') === 'reply') {
    verifyCsrf();
    $msg = post('message');
    if (!$msg) {
        $errors[] = 'Reply message cannot be empty.';
    } else {
        $isAdminReply = isAdmin() ? 1 : 0;
        $db->prepare("INSERT INTO support_replies (ticket_id, user_id, message, is_admin_reply) VALUES (?,?,?,?)")
           ->execute([$id, $me['id'], $msg, $isAdminReply]);
        // Notify the other party
        if ($isAdminReply) {
            $db->prepare("UPDATE support_tickets SET business_read=0, admin_read=1, updated_at=NOW() WHERE id=?")->execute([$id]);
        } else {
            $db->prepare("UPDATE support_tickets SET admin_read=0, business_read=1, updated_at=NOW() WHERE id=?")->execute([$id]);
        }
        flash('success', 'Reply sent.');
        redirect(url('support/view') . "?id={$id}");
    }
}

// Load replies
$repliesStmt = $db->prepare("SELECT r.*, u.name AS author_name FROM support_replies r
    LEFT JOIN users u ON u.id = r.user_id
    WHERE r.ticket_id=? ORDER BY r.created_at ASC");
$repliesStmt->execute([$id]);
$replies = $repliesStmt->fetchAll();

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

$pageTitle = 'Support Ticket #' . $id;
include __DIR__ . '/../../app/includes/header.php';
include __DIR__ . '/../../app/includes/sidebar.php';
?>

<div class="flex items-center gap-3 mb-6">
    <a href="<?= isAdmin() ? 'tickets.php' : 'index.php' ?>" class="text-gray-500 hover:text-gray-700">
        <i class="fa-solid fa-arrow-left"></i>
    </a>
    <div>
        <h2 class="text-xl font-bold text-gray-800"><?= h($ticket['subject']) ?></h2>
        <p class="text-sm text-gray-400">Ticket #<?= $id ?> · Submitted <?= formatDateTime($ticket['created_at']) ?></p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Main: thread -->
    <div class="lg:col-span-2 space-y-4">

        <!-- Original message -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-sm flex-shrink-0">
                    <?= strtoupper(substr($ticket['submitter_name'] ?? '?', 0, 1)) ?>
                </div>
                <div>
                    <p class="text-sm font-semibold text-gray-800"><?= h($ticket['submitter_name'] ?? 'Unknown') ?>
                        <?php if (isAdmin()): ?>
                        <span class="text-xs text-gray-400 font-normal">from <?= h($ticket['biz_name'] ?? '') ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="text-xs text-gray-400"><?= formatDateTime($ticket['created_at']) ?></p>
                </div>
                <span class="ml-auto text-xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded">Original</span>
            </div>
            <p class="text-sm text-gray-700 whitespace-pre-wrap"><?= h($ticket['message']) ?></p>
        </div>

        <!-- Replies -->
        <?php foreach ($replies as $reply): ?>
        <div class="rounded-xl p-5 <?= $reply['is_admin_reply'] ? 'bg-indigo-50 border border-indigo-100' : 'bg-white border border-gray-100 shadow-sm' ?>">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 rounded-full <?= $reply['is_admin_reply'] ? 'bg-indigo-600' : 'bg-gray-600' ?> text-white flex items-center justify-center font-bold text-sm flex-shrink-0">
                    <?= strtoupper(substr($reply['author_name'] ?? '?', 0, 1)) ?>
                </div>
                <div>
                    <p class="text-sm font-semibold text-gray-800"><?= h($reply['author_name'] ?? 'Unknown') ?>
                        <?php if ($reply['is_admin_reply']): ?>
                        <span class="text-xs bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded font-medium">Support Team</span>
                        <?php endif; ?>
                    </p>
                    <p class="text-xs text-gray-400"><?= formatDateTime($reply['created_at']) ?></p>
                </div>
            </div>
            <p class="text-sm text-gray-700 whitespace-pre-wrap"><?= h($reply['message']) ?></p>
        </div>
        <?php endforeach; ?>

        <!-- Reply form (only if not closed) -->
        <?php if ($ticket['status'] !== 'closed'): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h4 class="font-semibold text-gray-800 mb-3 text-sm">Add Reply</h4>
            <?php if (!empty($errors)): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-3">
                <?php foreach ($errors as $e): ?><p class="text-red-700 text-sm"><?= h($e) ?></p><?php endforeach; ?>
            </div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="_action" value="reply">
                <textarea name="message" rows="4" placeholder="Type your reply..."
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 outline-none resize-none mb-3"></textarea>
                <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-blue-700">
                    <i class="fa-solid fa-paper-plane mr-1"></i> Send Reply
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar: ticket info -->
    <div class="space-y-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h4 class="font-semibold text-gray-700 text-sm mb-3">Ticket Details</h4>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-500">Status</span>
                    <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $statusColors[$ticket['status']] ?>">
                        <?= ucwords(str_replace('_',' ',$ticket['status'])) ?>
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Priority</span>
                    <span class="px-2 py-0.5 text-xs font-medium rounded-full <?= $priorityColors[$ticket['priority']] ?>">
                        <?= ucfirst($ticket['priority']) ?>
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">Replies</span>
                    <span class="font-medium"><?= count($replies) ?></span>
                </div>
                <?php if (isAdmin()): ?>
                <div class="flex justify-between">
                    <span class="text-gray-500">Business</span>
                    <span class="font-medium text-right text-xs"><?= h($ticket['biz_name'] ?? '—') ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isAdmin()): ?>
        <!-- Admin: change status -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <h4 class="font-semibold text-gray-700 text-sm mb-3">Update Status</h4>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="_action" value="status">
                <select name="status" class="w-full border border-gray-300 rounded-lg text-sm px-3 py-2 outline-none mb-3">
                    <option value="open"        <?= $ticket['status']==='open'        ?'selected':'' ?>>Open</option>
                    <option value="in_progress" <?= $ticket['status']==='in_progress' ?'selected':'' ?>>In Progress</option>
                    <option value="resolved"    <?= $ticket['status']==='resolved'    ?'selected':'' ?>>Resolved</option>
                    <option value="closed"      <?= $ticket['status']==='closed'      ?'selected':'' ?>>Closed</option>
                </select>
                <button type="submit" class="w-full bg-gray-800 text-white py-2 rounded-lg text-sm font-medium hover:bg-gray-700">
                    Update Status
                </button>
            </form>
        </div>
        <?php else: ?>
        <!-- Business user: close their own ticket -->
        <?php if (!in_array($ticket['status'], ['resolved','closed'])): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="_action" value="status">
                <input type="hidden" name="status"  value="closed">
                <button type="submit" class="w-full border border-gray-300 text-gray-600 py-2 rounded-lg text-sm hover:bg-gray-50"
                        onclick="return confirm('Close this support ticket?')">
                    <i class="fa-solid fa-xmark mr-1"></i> Close Ticket
                </button>
            </form>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../app/includes/footer.php'; ?>
