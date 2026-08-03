<?php
$pageTitle = 'Demo Requests';
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../app/includes/functions.php';
require_once __DIR__ . '/../../app/includes/mail.php';
requireLogin();
if (!isAdmin()) { redirect(url('dashboard')); }

$db = getDB();

// Ensure table + slot columns exist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `landing_demos` (
        `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name`             VARCHAR(100),
        `email`            VARCHAR(255),
        `business_name`    VARCHAR(150),
        `phone`            VARCHAR(30),
        `message`          TEXT,
        `status`           VARCHAR(20) NOT NULL DEFAULT 'pending',
        `slot_date`        DATE NULL,
        `slot_time`        TIME NULL,
        `slot_notes`       TEXT NULL,
        `slot_assigned_at` TIMESTAMP NULL,
        `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Exception $e) {}
foreach ([
    "ALTER TABLE `landing_demos` ADD COLUMN `status`           VARCHAR(20)  NOT NULL DEFAULT 'pending'",
    "ALTER TABLE `landing_demos` ADD COLUMN `slot_date`        DATE         NULL",
    "ALTER TABLE `landing_demos` ADD COLUMN `slot_time`        TIME         NULL",
    "ALTER TABLE `landing_demos` ADD COLUMN `slot_notes`       TEXT         NULL",
    "ALTER TABLE `landing_demos` ADD COLUMN `slot_assigned_at` TIMESTAMP    NULL",
] as $__ms) { try { $db->exec($__ms); } catch (\Exception $e) {} }

// ─── POST ──────────────────────────────────────────────────────────────────
if (isPost()) {
    verifyCsrf();
    $action = trim(post('_action'));
    $id     = (int)post('id');

    $stmt = $db->prepare("SELECT * FROM landing_demos WHERE id=?");
    $stmt->execute([$id]);
    $demo = $stmt->fetch();

    if ($demo) {
        if (in_array($action, ['allocate_slot', 'update_slot'])) {
            $slotDate  = trim(post('slot_date'));
            $slotTime  = trim(post('slot_time'));
            $slotNotes = trim(post('slot_notes'));

            if (!$slotDate || !$slotTime) {
                flash('error', 'A date and time are required to allocate a slot.');
                redirect(url('admin/demos'));
            }

            $db->prepare("UPDATE landing_demos SET status='scheduled', slot_date=?, slot_time=?, slot_notes=?, slot_assigned_at=NOW() WHERE id=?")
               ->execute([$slotDate, $slotTime, $slotNotes, $id]);

            // Build & send confirmation email
            $appName      = APP_NAME;
            $isUpdate     = ($action === 'update_slot');
            $dateFmt      = date('l, d F Y', strtotime($slotDate));
            $timeFmt      = date('g:i A', strtotime($slotTime));
            $demoNameHtml = htmlspecialchars($demo['name'], ENT_QUOTES, 'UTF-8');
            $emailSubject = $isUpdate
                ? "[{$appName}] Your Demo Slot Has Been Updated"
                : "[{$appName}] Your Demo is Confirmed!";
            $updateBanner = $isUpdate
                ? "<div style='margin-bottom:20px;padding:11px 16px;background:#fef9ec;border-left:4px solid #C9A84C;border-radius:0 8px 8px 0'><strong style='font-size:13px;color:#92400e'>Your slot has been updated — please check the new details below.</strong></div>"
                : '';
            $notesBlock = '';
            if ($slotNotes) {
                $notesHtml  = nl2br(htmlspecialchars($slotNotes, ENT_QUOTES, 'UTF-8'));
                $notesBlock = "<div style='margin-top:16px;padding:14px 16px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px'><p style='margin:0 0 6px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#0369a1'>Meeting Details / Notes</p><p style='margin:0;font-size:13.5px;color:#1e40af;line-height:1.65'>{$notesHtml}</p></div>";
            }
            $emailHtml = "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#f1f5f9;font-family:Inter,Arial,sans-serif'>
<div style='max-width:580px;margin:0 auto;padding:28px 16px'>
<div style='background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.07)'>
<div style='background:linear-gradient(135deg,#0e1f3f,#1B3263);padding:22px 28px'><span style='font-size:19px;font-weight:800;color:#fff'>{$appName}</span></div>
<div style='padding:28px'>
  {$updateBanner}
  <h2 style='font-size:21px;font-weight:800;color:#0e1f3f;margin:0 0 6px'>" . ($isUpdate ? 'Demo Slot Updated' : 'Demo Confirmed!') . "</h2>
  <p style='font-size:14px;color:#64748b;margin:0 0 22px'>Hi {$demoNameHtml}, here are your confirmed session details.</p>
  <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px'>
    <div style='display:flex;gap:14px;align-items:center;padding-bottom:14px;border-bottom:1px solid #e2e8f0;margin-bottom:14px'>
      <div style='width:42px;height:42px;background:#e8f0fb;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0'>📅</div>
      <div><div style='font-size:10.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#94a3b8;margin-bottom:3px'>Date</div><div style='font-size:17px;font-weight:700;color:#0e1f3f'>{$dateFmt}</div></div>
    </div>
    <div style='display:flex;gap:14px;align-items:center'>
      <div style='width:42px;height:42px;background:#e8f0fb;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0'>🕐</div>
      <div><div style='font-size:10.5px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:#94a3b8;margin-bottom:3px'>Time</div><div style='font-size:17px;font-weight:700;color:#0e1f3f'>{$timeFmt}</div></div>
    </div>
  </div>
  {$notesBlock}
  <div style='margin-top:18px;padding:14px 16px;background:#fef9ec;border:1px solid rgba(201,168,76,.25);border-radius:10px'>
    <p style='margin:0 0 8px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#92400e'>What to expect</p>
    <ul style='margin:0;padding-left:18px;font-size:13.5px;color:#78350f;line-height:1.9'>
      <li>A live 30-minute walkthrough of {$appName}</li>
      <li>Tailored to your specific business type</li>
      <li>Open Q&amp;A session with our team</li>
    </ul>
  </div>
  <p style='font-size:13px;color:#94a3b8;margin:20px 0 0;text-align:center'>Need to reschedule? Reply to this email or contact us via the website.</p>
</div>
<div style='background:#f8fafc;border-top:1px solid #e2e8f0;padding:13px 28px;text-align:center'><p style='margin:0;font-size:12px;color:#94a3b8'>&copy; " . date('Y') . " {$appName}. All rights reserved.</p></div>
</div></div></body></html>";

            appMail($demo['email'], $emailSubject, $emailHtml, $demo['name']);
            flash('success', ($isUpdate ? 'Slot updated' : 'Slot allocated') . ' — confirmation email sent to ' . h($demo['email']));

        } elseif ($action === 'mark_complete') {
            $db->prepare("UPDATE landing_demos SET status='completed' WHERE id=?")->execute([$id]);
            flash('success', 'Demo marked as completed.');
        } elseif ($action === 'cancel') {
            $db->prepare("UPDATE landing_demos SET status='cancelled' WHERE id=?")->execute([$id]);
            flash('success', 'Demo request cancelled.');
        } elseif ($action === 'reopen') {
            $db->prepare("UPDATE landing_demos SET status='pending', slot_date=NULL, slot_time=NULL, slot_notes=NULL, slot_assigned_at=NULL WHERE id=?")->execute([$id]);
            flash('success', 'Request reopened as pending.');
        }
    }
    redirect(url('admin/demos'));
}

// ─── Filters & Pagination ──────────────────────────────────────────────────
$search       = trim(get('q', ''));
$filterStatus = get('status', '');
$perPage      = 20;
$page         = max(1, (int)get('page', 1));
$offset       = ($page - 1) * $perPage;

$wParts = []; $wParams = [];
if ($search !== '') {
    $wParts[]  = '(name LIKE ? OR email LIKE ? OR business_name LIKE ?)';
    $wParams   = array_merge($wParams, ['%'.$search.'%', '%'.$search.'%', '%'.$search.'%']);
}
if ($filterStatus !== '') {
    $wParts[]  = 'status = ?';
    $wParams[] = $filterStatus;
}
$wSQL = $wParts ? 'WHERE ' . implode(' AND ', $wParts) : '';

$demos = []; $totalRows = 0;
$stats = ['total' => 0, 'pending' => 0, 'scheduled' => 0, 'completed' => 0, 'cancelled' => 0];
try {
    $cStmt = $db->prepare("SELECT COUNT(*) FROM landing_demos {$wSQL}");
    $cStmt->execute($wParams);
    $totalRows = (int)$cStmt->fetchColumn();

    $orderSQL = "ORDER BY FIELD(status,'pending','scheduled','completed','cancelled'), created_at DESC";
    $dStmt    = $db->prepare("SELECT * FROM landing_demos {$wSQL} {$orderSQL} LIMIT {$perPage} OFFSET {$offset}");
    $dStmt->execute($wParams);
    $demos = $dStmt->fetchAll();

    $sRow  = $db->query("SELECT COUNT(*) total,
        COALESCE(SUM(status='pending'),0)   pending,
        COALESCE(SUM(status='scheduled'),0) scheduled,
        COALESCE(SUM(status='completed'),0) completed,
        COALESCE(SUM(status='cancelled'),0) cancelled
        FROM landing_demos")->fetch();
    if ($sRow) $stats = $sRow;
} catch (\Exception $e) {}

$totalPages = $perPage > 0 ? max(1, (int)ceil($totalRows / $perPage)) : 1;
$filterBase = http_build_query(array_filter(['q' => $search, 'status' => $filterStatus]));
$pageUrl    = fn(int $p) => url('admin/demos') . '?' . ($filterBase ? $filterBase . '&' : '') . 'page=' . $p;

$statusMeta = [
    'pending'   => ['Pending',   'bg-amber-100 text-amber-700 border-amber-200'],
    'scheduled' => ['Scheduled', 'bg-blue-100 text-blue-700 border-blue-200'],
    'completed' => ['Completed', 'bg-green-100 text-green-700 border-green-200'],
    'cancelled' => ['Cancelled', 'bg-gray-100 text-gray-500 border-gray-200'],
];

require_once __DIR__ . '/../../app/includes/header.php';
require_once __DIR__ . '/../../app/includes/sidebar.php';
?>

<!-- ─── Stats Cards ──────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <?php
    $cards = [
        ['Total',     $stats['total'],     'fa-inbox',          '#1B3263', '#e8f0fb'],
        ['Pending',   $stats['pending'],   'fa-clock',          '#d97706', '#fef3c7'],
        ['Scheduled', $stats['scheduled'], 'fa-calendar-check', '#2563eb', '#dbeafe'],
        ['Completed', $stats['completed'], 'fa-circle-check',   '#16a34a', '#dcfce7'],
        ['Cancelled', $stats['cancelled'], 'fa-ban',            '#6b7280', '#f3f4f6'],
    ];
    foreach ($cards as [$label, $val, $icon, $color, $bg]): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style="background:<?= $bg ?>">
            <i class="fa-solid <?= $icon ?>" style="color:<?= $color ?>;font-size:15px"></i>
        </div>
        <div>
            <div class="text-2xl font-black" style="color:<?= $color ?>;letter-spacing:-1px;line-height:1"><?= number_format((int)$val) ?></div>
            <div class="text-xs text-gray-500 font-medium mt-0.5"><?= $label ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ─── Filter Bar ───────────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm mb-5">
    <form method="GET" class="flex flex-wrap items-center gap-3 p-4">
        <div class="flex-1 min-w-48 relative">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
            <input type="text" name="q" value="<?= h($search) ?>"
                   placeholder="Search by name, email or business…"
                   class="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-100">
        </div>
        <select name="status" class="text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:border-blue-400 text-gray-700">
            <option value="">All Statuses</option>
            <?php foreach ($statusMeta as $k => [$lbl, $_]): ?>
            <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="px-4 py-2 bg-navy text-white text-sm font-semibold rounded-lg hover:bg-navy-mid transition-colors" style="background:#1B3263">
            <i class="fa-solid fa-filter mr-1.5"></i> Filter
        </button>
        <?php if ($search || $filterStatus): ?>
        <a href="<?= url('admin/demos') ?>" class="px-4 py-2 text-sm text-gray-500 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">
            <i class="fa-solid fa-xmark mr-1"></i> Clear
        </a>
        <?php endif; ?>
        <span class="ml-auto text-xs text-gray-400 whitespace-nowrap">
            <?= number_format($totalRows) ?> request<?= $totalRows !== 1 ? 's' : '' ?>
        </span>
    </form>
</div>

<!-- ─── Table ────────────────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mb-5">
    <?php if (empty($demos)): ?>
    <div class="flex flex-col items-center justify-center py-20 text-center">
        <div class="w-16 h-16 rounded-2xl bg-gray-100 flex items-center justify-center mb-4">
            <i class="fa-solid fa-calendar-xmark text-2xl text-gray-400"></i>
        </div>
        <p class="text-gray-500 font-semibold">No demo requests found</p>
        <p class="text-gray-400 text-sm mt-1"><?= $search || $filterStatus ? 'Try adjusting your filters.' : 'Demo requests from the landing page will appear here.' ?></p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 border-b border-gray-100 text-xs font-700 text-gray-500 uppercase tracking-wide">
                    <th class="px-4 py-3 text-left w-8">#</th>
                    <th class="px-4 py-3 text-left">Requester</th>
                    <th class="px-4 py-3 text-left">Business</th>
                    <th class="px-4 py-3 text-left hidden md:table-cell">Message</th>
                    <th class="px-4 py-3 text-center w-28">Status</th>
                    <th class="px-4 py-3 text-left hidden lg:table-cell">Slot</th>
                    <th class="px-4 py-3 text-left hidden xl:table-cell">Requested</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($demos as $i => $demo):
                $status    = $demo['status'] ?? 'pending';
                $hasSlot   = !empty($demo['slot_date']);
                [$stLabel, $stCls] = $statusMeta[$status] ?? ['Unknown', 'bg-gray-100 text-gray-500'];
                $slotDisplay = $hasSlot
                    ? date('d M Y', strtotime($demo['slot_date'])) . ' · ' . date('g:i A', strtotime($demo['slot_time']))
                    : '—';
                $msgPreview = mb_strlen($demo['message'] ?? '') > 70
                    ? mb_substr($demo['message'], 0, 70) . '…'
                    : ($demo['message'] ?? '—');
            ?>
            <tr class="hover:bg-gray-50/50 transition-colors">
                <td class="px-4 py-3 text-gray-400 font-medium"><?= $offset + $i + 1 ?></td>

                <!-- Requester -->
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-[#1B3263] to-[#2952a3] flex items-center justify-center text-white font-bold text-xs flex-shrink-0">
                            <?= strtoupper(substr($demo['name'] ?? '?', 0, 1)) ?>
                        </div>
                        <div>
                            <div class="font-semibold text-gray-800"><?= h($demo['name'] ?? '') ?></div>
                            <div class="text-xs text-gray-400"><?= h($demo['email'] ?? '') ?></div>
                            <?php if ($demo['phone']): ?>
                            <div class="text-xs text-gray-400"><?= h($demo['phone']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>

                <!-- Business -->
                <td class="px-4 py-3 text-gray-600"><?= h($demo['business_name'] ?? '—') ?></td>

                <!-- Message -->
                <td class="px-4 py-3 hidden md:table-cell">
                    <span class="text-gray-500 text-xs leading-relaxed"><?= h($msgPreview) ?></span>
                </td>

                <!-- Status -->
                <td class="px-4 py-3 text-center">
                    <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-700 border <?= $stCls ?>"><?= $stLabel ?></span>
                </td>

                <!-- Slot -->
                <td class="px-4 py-3 hidden lg:table-cell">
                    <?php if ($hasSlot): ?>
                    <div class="text-xs">
                        <div class="font-semibold text-gray-700"><?= date('d M Y', strtotime($demo['slot_date'])) ?></div>
                        <div class="text-blue-600 font-medium"><?= date('g:i A', strtotime($demo['slot_time'])) ?></div>
                        <?php if ($demo['slot_notes']): ?>
                        <div class="text-gray-400 mt-0.5 truncate max-w-[140px]" title="<?= h($demo['slot_notes']) ?>">
                            <i class="fa-solid fa-note-sticky mr-1"></i><?= h(mb_substr($demo['slot_notes'], 0, 30)) ?>…
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <span class="text-gray-300 text-xs">Not scheduled</span>
                    <?php endif; ?>
                </td>

                <!-- Requested At -->
                <td class="px-4 py-3 hidden xl:table-cell text-xs text-gray-400">
                    <?= date('d M Y', strtotime($demo['created_at'])) ?><br>
                    <span class="text-gray-300"><?= date('g:i A', strtotime($demo['created_at'])) ?></span>
                </td>

                <!-- Actions -->
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-1.5">
                    <?php if ($status === 'pending'): ?>
                        <button type="button"
                            data-slot="<?= htmlspecialchars(json_encode([
                                'id'     => (int)$demo['id'],
                                'action' => 'allocate_slot',
                                'title'  => 'Allocate Demo Slot',
                                'name'   => $demo['name']          ?? '',
                                'email'  => $demo['email']         ?? '',
                                'biz'    => $demo['business_name'] ?? '',
                                'phone'  => $demo['phone']         ?? '',
                                'msg'    => $demo['message']       ?? '',
                                'date'   => '',
                                'time'   => '',
                                'notes'  => '',
                            ]), ENT_QUOTES, 'UTF-8') ?>"
                            onclick="openSlotModal(JSON.parse(this.dataset.slot))"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#1B3263] text-white text-xs font-semibold rounded-lg hover:bg-[#142552] transition-colors">
                            <i class="fa-solid fa-calendar-plus"></i> Allocate Slot
                        </button>

                    <?php elseif ($status === 'scheduled'): ?>
                        <button type="button"
                            data-slot="<?= htmlspecialchars(json_encode([
                                'id'     => (int)$demo['id'],
                                'action' => 'update_slot',
                                'title'  => 'Update Demo Slot',
                                'name'   => $demo['name']          ?? '',
                                'email'  => $demo['email']         ?? '',
                                'biz'    => $demo['business_name'] ?? '',
                                'phone'  => $demo['phone']         ?? '',
                                'msg'    => $demo['message']       ?? '',
                                'date'   => $demo['slot_date']     ?? '',
                                'time'   => $demo['slot_time']     ? substr($demo['slot_time'], 0, 5) : '',
                                'notes'  => $demo['slot_notes']    ?? '',
                            ]), ENT_QUOTES, 'UTF-8') ?>"
                            onclick="openSlotModal(JSON.parse(this.dataset.slot))"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-blue-50 text-blue-700 border border-blue-200 text-xs font-semibold rounded-lg hover:bg-blue-100 transition-colors">
                            <i class="fa-solid fa-pen-to-square"></i> Edit Slot
                        </button>
                        <button type="button"
                            onclick="demoAction('mark_complete', <?= (int)$demo['id'] ?>, 'Mark this demo as completed?')"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-green-50 text-green-700 border border-green-200 text-xs font-semibold rounded-lg hover:bg-green-100 transition-colors"
                            title="Mark as Completed">
                            <i class="fa-solid fa-circle-check"></i>
                        </button>
                        <button type="button"
                            onclick="demoAction('cancel', <?= (int)$demo['id'] ?>, 'Cancel this demo request?')"
                            class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-red-50 text-red-600 border border-red-200 text-xs font-semibold rounded-lg hover:bg-red-100 transition-colors"
                            title="Cancel">
                            <i class="fa-solid fa-ban"></i>
                        </button>

                    <?php else: ?>
                        <button type="button"
                            onclick="demoAction('reopen', <?= (int)$demo['id'] ?>, 'Reopen this request as pending?')"
                            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-gray-100 text-gray-600 border border-gray-200 text-xs font-semibold rounded-lg hover:bg-gray-200 transition-colors">
                            <i class="fa-solid fa-rotate-left"></i> Reopen
                        </button>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ─── Pagination ───────────────────────────────────────────────────────── -->
<?php if ($totalPages > 1): ?>
<div class="flex items-center justify-between text-sm mb-6">
    <span class="text-gray-500">Page <?= $page ?> of <?= $totalPages ?></span>
    <div class="flex gap-1">
        <?php if ($page > 1): ?>
        <a href="<?= $pageUrl($page - 1) ?>" class="px-3 py-1.5 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50">
            <i class="fa-solid fa-chevron-left text-xs"></i>
        </a>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
        if ($start > 1): ?><a href="<?= $pageUrl(1) ?>" class="px-3 py-1.5 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50">1</a><?php
        if ($start > 2) echo '<span class="px-2 py-1.5 text-gray-400">…</span>';
        endif;
        for ($p = $start; $p <= $end; $p++):
            $cls = $p === $page ? 'px-3 py-1.5 rounded-lg font-semibold text-white' : 'px-3 py-1.5 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50';
            $style = $p === $page ? 'style="background:#1B3263"' : '';
            echo "<a href=\"{$pageUrl($p)}\" class=\"{$cls}\" {$style}>{$p}</a>";
        endfor;
        if ($end < $totalPages):
        if ($end < $totalPages - 1) echo '<span class="px-2 py-1.5 text-gray-400">…</span>';
        ?><a href="<?= $pageUrl($totalPages) ?>" class="px-3 py-1.5 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50"><?= $totalPages ?></a><?php
        endif; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?= $pageUrl($page + 1) ?>" class="px-3 py-1.5 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50">
            <i class="fa-solid fa-chevron-right text-xs"></i>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ─── Shared POST Form (for quick actions) ─────────────────────────────── -->
<form id="demoActionForm" method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action"     id="demoActionType" value="">
    <input type="hidden" name="id"          id="demoActionId"   value="">
</form>

<!-- ─── Slot Allocation Modal ────────────────────────────────────────────── -->
<div id="slotModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeSlotModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto animate-in">

        <!-- Modal Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 sticky top-0 bg-white rounded-t-2xl z-10">
            <div>
                <h3 id="slotModalTitle" class="font-bold text-gray-800 text-lg">Allocate Demo Slot</h3>
                <p class="text-xs text-gray-400 mt-0.5">An email will be sent to the requester with the confirmed slot.</p>
            </div>
            <button type="button" onclick="closeSlotModal()" class="text-gray-400 hover:text-gray-600 w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- Requester Info -->
        <div id="slotRequesterInfo" class="mx-6 mt-5 p-4 bg-gray-50 rounded-xl border border-gray-100">
            <p class="text-xs font-700 text-gray-400 uppercase tracking-wide mb-3">Requester Details</p>
            <div class="grid grid-cols-2 gap-3 text-sm mb-3">
                <div>
                    <div class="text-xs text-gray-400 mb-0.5">Name</div>
                    <div id="slotRName" class="font-semibold text-gray-700"></div>
                </div>
                <div>
                    <div class="text-xs text-gray-400 mb-0.5">Email</div>
                    <div id="slotREmail" class="font-semibold text-gray-700 break-all"></div>
                </div>
                <div>
                    <div class="text-xs text-gray-400 mb-0.5">Business</div>
                    <div id="slotRBiz" class="text-gray-600"></div>
                </div>
                <div>
                    <div class="text-xs text-gray-400 mb-0.5">Phone</div>
                    <div id="slotRPhone" class="text-gray-600"></div>
                </div>
            </div>
            <div>
                <div class="text-xs text-gray-400 mb-1">Their Message</div>
                <div id="slotRMsg" class="text-gray-600 text-xs leading-relaxed bg-white p-2.5 rounded-lg border border-gray-100"></div>
            </div>
        </div>

        <!-- Slot Form -->
        <form method="POST" class="px-6 pb-6 pt-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="_action"     id="slotAction" value="allocate_slot">
            <input type="hidden" name="id"          id="slotId"     value="">

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-700 text-gray-700 mb-1.5">
                        Demo Date <span class="text-red-500">*</span>
                    </label>
                    <input type="date" name="slot_date" id="slotDate" required
                           min="<?= date('Y-m-d') ?>"
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-100">
                </div>
                <div>
                    <label class="block text-xs font-700 text-gray-700 mb-1.5">
                        Demo Time <span class="text-red-500">*</span>
                    </label>
                    <input type="time" name="slot_time" id="slotTime" required
                           class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-100">
                </div>
            </div>

            <div class="mb-5">
                <label class="block text-xs font-700 text-gray-700 mb-1.5">
                    Meeting Link / Notes
                    <span class="font-normal text-gray-400">(optional — included in the email)</span>
                </label>
                <textarea name="slot_notes" id="slotNotes" rows="3"
                          placeholder="e.g. Zoom link: https://zoom.us/j/… or Google Meet link, phone number for dial-in, etc."
                          class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-blue-400 focus:ring-1 focus:ring-blue-100 resize-none"></textarea>
            </div>

            <div class="flex gap-3">
                <button type="submit"
                        class="flex-1 py-2.5 text-sm font-bold text-white rounded-xl transition-all hover:opacity-90"
                        style="background:linear-gradient(135deg,#1B3263,#2952a3)">
                    <i class="fa-solid fa-paper-plane mr-1.5"></i>
                    <span id="slotSubmitLabel">Confirm &amp; Send Email</span>
                </button>
                <button type="button" onclick="closeSlotModal()"
                        class="px-5 py-2.5 text-sm font-semibold text-gray-600 border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openSlotModal(data) {
    document.getElementById('slotId').value           = data.id;
    document.getElementById('slotAction').value       = data.action;
    document.getElementById('slotModalTitle').textContent = data.title;
    document.getElementById('slotDate').value         = data.date  || '';
    document.getElementById('slotTime').value         = data.time  || '';
    document.getElementById('slotNotes').value        = data.notes || '';
    document.getElementById('slotRName').textContent  = data.name  || '—';
    document.getElementById('slotREmail').textContent = data.email || '—';
    document.getElementById('slotRBiz').textContent   = data.biz   || '—';
    document.getElementById('slotRPhone').textContent = data.phone || '—';
    document.getElementById('slotRMsg').textContent   = data.msg   || '—';
    document.getElementById('slotSubmitLabel').textContent =
        data.action === 'update_slot' ? 'Update & Resend Email' : 'Confirm & Send Email';
    document.getElementById('slotModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeSlotModal() {
    document.getElementById('slotModal').classList.add('hidden');
    document.body.style.overflow = '';
}

function demoAction(action, id, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return;
    document.getElementById('demoActionType').value = action;
    document.getElementById('demoActionId').value   = id;
    document.getElementById('demoActionForm').submit();
}

// Close on Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSlotModal(); });
</script>

<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
