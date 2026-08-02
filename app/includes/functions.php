<?php
// ============================================================
// Global Helper Functions
// ============================================================

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderComingSoon(string $title, string $icon = 'fa-clock', string $description = ''): void {
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeDesc  = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
    $descHtml  = $safeDesc ? "<p class=\"text-gray-400 text-sm leading-relaxed mb-6\">{$safeDesc}</p>" : '';
    echo "
    <div class=\"flex flex-col items-center justify-center min-h-64 py-20 text-center max-w-lg mx-auto\">
        <div class=\"relative mb-6\">
            <div class=\"w-24 h-24 rounded-2xl bg-gradient-to-br from-blue-100 to-indigo-100 flex items-center justify-center mx-auto\">
                <i class=\"fa-solid {$icon} text-4xl text-blue-400\"></i>
            </div>
            <span class=\"absolute -top-2 -right-2 bg-amber-400 text-white text-xs font-bold px-2 py-0.5 rounded-full shadow\">SOON</span>
        </div>
        <h2 class=\"text-2xl font-bold text-gray-800 mb-2\">{$safeTitle}</h2>
        <p class=\"text-sm font-semibold text-amber-600 uppercase tracking-widest mb-4\">Coming Soon</p>
        {$descHtml}
        <a href=\"" . SITE_URL . "/dashboard\" class=\"inline-flex items-center gap-2 bg-blue-600 text-white px-5 py-2 rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors\">
            <i class=\"fa-solid fa-arrow-left\"></i> Back to Dashboard
        </a>
    </div>";
}

function formatMoney(float $amount, string $symbol = null): string {
    $sym = $symbol
        ?? $_SESSION['business_currency']
        ?? (defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'NLE');
    return $sym . ' ' . number_format($amount, 2);
}

function formatDate(?string $date): string {
    if (!$date) return '-';
    return date(DATE_FORMAT, strtotime($date));
}

function formatDateTime(?string $dt): string {
    if (!$dt) return '-';
    return date(DATETIME_FORMAT, strtotime($dt));
}

function generateInvoiceNumber(int $businessId): string {
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM sales WHERE business_id = ?');
    $stmt->execute([$businessId]);
    $count = (int)$stmt->fetchColumn() + 1;
    return INVOICE_PREFIX . date('Ymd') . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
}

function generateReference(): string {
    return strtoupper(bin2hex(random_bytes(6)));
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function renderFlash(): string {
    $flash = getFlash();
    if (!$flash) return '';
    $colors = [
        'success' => 'bg-green-100 border-green-500 text-green-800',
        'error'   => 'bg-red-100 border-red-500 text-red-800',
        'warning' => 'bg-yellow-100 border-yellow-500 text-yellow-800',
        'info'    => 'bg-blue-100 border-blue-500 text-blue-800',
    ];
    $cls = $colors[$flash['type']] ?? $colors['info'];
    $msg = h($flash['message']);
    return "<div class=\"{$cls} border-l-4 p-4 mb-4 rounded\" role=\"alert\">
                <p>{$msg}</p>
            </div>";
}

function paginate(int $total, int $page, int $perPage = RECORDS_PER_PAGE): array {
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    return ['total' => $total, 'page' => $page, 'per_page' => $perPage,
            'total_pages' => $totalPages, 'offset' => $offset];
}

function renderPagination(array $pag, string $url): string {
    if ($pag['total_pages'] <= 1) return '';
    $html = '<div class="flex items-center justify-between mt-4">';
    $html .= '<p class="text-sm text-gray-600">Showing page ' . $pag['page'] . ' of ' . $pag['total_pages'] . ' (' . $pag['total'] . ' records)</p>';
    $html .= '<div class="flex gap-1">';
    for ($i = 1; $i <= $pag['total_pages']; $i++) {
        $active = $i === $pag['page'] ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100';
        $sep = strpos($url, '?') !== false ? '&' : '?';
        $html .= "<a href=\"{$url}{$sep}page={$i}\" class=\"px-3 py-1 text-sm border rounded {$active}\">{$i}</a>";
    }
    $html .= '</div></div>';
    return $html;
}

function auditLog(string $action, string $module, int $recordId = null, array $old = [], array $new = []): void {
    try {
        $db   = getDB();
        $user = currentUser();
        $stmt = $db->prepare('INSERT INTO audit_logs (business_id, user_id, action, module, record_id, old_values, new_values, ip_address, user_agent) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $user['business_id'],
            $user['id'],
            $action,
            $module,
            $recordId,
            $old ? json_encode($old) : null,
            $new ? json_encode($new) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Exception $e) {
        // Audit log failures should never break the app
        error_log('Audit log error: ' . $e->getMessage());
    }
}

function sanitize(mixed $value): mixed {
    if (is_string($value)) {
        return trim(strip_tags($value));
    }
    return $value;
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function url(string $path = ''): string {
    return SITE_URL . '/' . ltrim($path, '/');
}

function isPost(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function post(string $key, mixed $default = ''): mixed {
    return sanitize($_POST[$key] ?? $default);
}

function get(string $key, mixed $default = ''): mixed {
    return sanitize($_GET[$key] ?? $default);
}

function createNotification(int $userId, string $type, string $title, string $body = '', string $link = ''): void {
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS `notifications` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`    INT UNSIGNED NOT NULL,
            `type`       VARCHAR(30) NOT NULL DEFAULT 'info',
            `title`      VARCHAR(150) NOT NULL,
            `body`       TEXT DEFAULT NULL,
            `link`       VARCHAR(500) DEFAULT NULL,
            `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `user_read` (`user_id`,`is_read`),
            KEY `created`   (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->prepare("INSERT INTO notifications (user_id, type, title, body, link) VALUES (?,?,?,?,?)")
           ->execute([$userId, $type, $title, $body ?: null, $link ?: null]);
    } catch (\Exception $e) {}
}

function notifyAdmins(string $type, string $title, string $body = '', string $link = ''): void {
    try {
        $db    = getDB();
        $admins = $db->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='admin' AND u.is_active=1")->fetchAll();
        foreach ($admins as $admin) {
            createNotification((int)$admin['id'], $type, $title, $body, $link);
        }
    } catch (\Exception $e) {}
}

function statusBadge(string $status): string {
    $map = [
        'paid'       => 'bg-green-100 text-green-800',
        'partial'    => 'bg-yellow-100 text-yellow-800',
        'unpaid'     => 'bg-red-100 text-red-800',
        'active'     => 'bg-blue-100 text-blue-800',
        'overdue'    => 'bg-red-100 text-red-800',
        'written_off'=> 'bg-gray-100 text-gray-800',
        'cash'       => 'bg-green-100 text-green-800',
        'credit'     => 'bg-orange-100 text-orange-800',
    ];
    $cls = $map[strtolower($status)] ?? 'bg-gray-100 text-gray-800';
    return "<span class=\"px-2 py-0.5 text-xs font-medium rounded-full {$cls}\">" . ucfirst(str_replace('_', ' ', $status)) . '</span>';
}
