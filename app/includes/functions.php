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

function currencySymbol(): string {
    return $_SESSION['business_currency'] ?? (defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : 'NLE');
}

function currentBranchId(): ?int {
    return !empty($_SESSION['active_branch_id']) ? (int)$_SESSION['active_branch_id'] : null;
}

function currentBranchName(): string {
    return $_SESSION['active_branch_name'] ?? 'All Branches';
}

function renderBranchBanner(): string {
    $bid = currentBranchId();
    if ($bid === null) return '';
    $name = htmlspecialchars(currentBranchName(), ENT_QUOTES, 'UTF-8');
    $csrf = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');
    $action = url('branches/switch');
    return '<div class="flex items-center gap-2 bg-blue-50 border border-blue-200 rounded-lg px-4 py-2.5 mb-4 text-sm text-blue-800">
        <i class="fa-solid fa-code-branch text-blue-500 flex-shrink-0"></i>
        <span>Showing <strong>' . $name . '</strong> branch records only.</span>
        <form method="POST" action="' . $action . '" class="ml-auto flex-shrink-0">
            <input type="hidden" name="csrf_token" value="' . $csrf . '">
            <input type="hidden" name="branch_id" value="0">
            <button type="submit" class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                <i class="fa-solid fa-xmark mr-1"></i>View all branches
            </button>
        </form>
    </div>';
}

/**
 * Check whether a business has hit a plan quota.
 * Returns ['limit'=>int|null, 'count'=>int, 'reached'=>bool].
 * limit===null means unlimited (no enforcement).
 */
function planLimitCheck(int $bizId, string $metric): array {
    $allowed = ['max_branches','max_orders_per_month','max_products','max_customers','max_users'];
    if (!in_array($metric, $allowed, true)) return ['limit'=>null,'count'=>0,'reached'=>false];
    try {
        $db = getDB();
        $planQ = $db->prepare(
            "SELECT sp.`{$metric}` FROM subscription_plans sp
             JOIN business_subscriptions bs ON bs.plan_id = sp.id
             WHERE bs.business_id = ? AND bs.status IN ('active','trial')
             LIMIT 1"
        );
        $planQ->execute([$bizId]);
        $row   = $planQ->fetch(PDO::FETCH_ASSOC);
        $limit = ($row && $row[$metric] !== null) ? (int)$row[$metric] : null;
        if ($limit === null) return ['limit'=>null,'count'=>0,'reached'=>false];
        if ($metric === 'max_branches') {
            $cQ = $db->prepare("SELECT COUNT(*) FROM branches WHERE business_id=? AND is_active=1");
        } elseif ($metric === 'max_orders_per_month') {
            $cQ = $db->prepare("SELECT COUNT(*) FROM sales WHERE business_id=? AND YEAR(sale_date)=YEAR(CURDATE()) AND MONTH(sale_date)=MONTH(CURDATE())");
        } elseif ($metric === 'max_products') {
            $cQ = $db->prepare("SELECT COUNT(*) FROM products WHERE business_id=? AND is_active=1");
        } elseif ($metric === 'max_customers') {
            $cQ = $db->prepare("SELECT COUNT(*) FROM customers WHERE business_id=? AND is_active=1");
        } else {
            $cQ = $db->prepare("SELECT COUNT(*) FROM users WHERE business_id=? AND is_active=1");
        }
        $cQ->execute([$bizId]);
        $count = (int)$cQ->fetchColumn();
        return ['limit'=>$limit,'count'=>$count,'reached'=>$count >= $limit];
    } catch (\Exception $e) {
        return ['limit'=>null,'count'=>0,'reached'=>false];
    }
}

function featureStatus(string $key): string {
    if (isAdmin()) return 'enabled';
    $bizId = currentBusinessId();
    if (!$bizId) return 'enabled';

    static $userCache = [];
    static $bizCache  = [];

    // Check per-user overrides (set by business owner for their staff).
    // Business owners have no user_feature_permissions rows, so they always fall through to business-level.
    $userId = (int)(currentUser()['id'] ?? 0);
    if ($userId && !array_key_exists($userId, $userCache)) {
        try {
            $q = getDB()->prepare("SELECT feature_key, status FROM user_feature_permissions WHERE user_id=?");
            $q->execute([$userId]);
            $userCache[$userId] = $q->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) { $userCache[$userId] = []; }
    }
    if ($userId && isset($userCache[$userId][$key])) {
        return $userCache[$userId][$key];
    }

    // Fall back to business-level feature status.
    if (!array_key_exists($bizId, $bizCache)) {
        try {
            $q = getDB()->prepare("SELECT feature_key, status FROM business_features WHERE business_id=?");
            $q->execute([$bizId]);
            $bizCache[$bizId] = $q->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) { $bizCache[$bizId] = []; }
    }
    return $bizCache[$bizId][$key] ?? 'enabled';
}

function requireFeature(string $key): void {
    $status = featureStatus($key);
    if ($status === 'disabled') {
        flash('error', 'This feature is not enabled for your account. Contact your administrator.');
        redirect(url('dashboard'));
    }
    if ($status === 'coming_soon') {
        redirect(url('coming-soon') . '?feature=' . urlencode($key));
    }
}

function countryDialCode(): string {
    $code = $_SESSION['business_country'] ?? '';
    if (!$code) return '';
    static $dialMap = null;
    if ($dialMap === null) {
        require_once __DIR__ . '/countries.php';
        $dialMap = array_column(countriesList(), 'dial', 'code');
    }
    return $dialMap[$code] ?? '';
}

function formatMoney(float $amount, string $symbol = null): string {
    return ($symbol ?? currencySymbol()) . ' ' . number_format($amount, 2);
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
        $db     = getDB();
        $admins = $db->query("SELECT u.id, u.email, u.name FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='admin' AND u.is_active=1")->fetchAll();
        foreach ($admins as $admin) {
            createNotification((int)$admin['id'], $type, $title, $body, $link);
            if (function_exists('appMail') && !empty($admin['email'])) {
                $appName = defined('APP_NAME') ? APP_NAME : 'Stocqify';
                $linkHtml = $link ? "<p style='margin-top:14px'><a href='" . htmlspecialchars($link) . "' style='color:#1B3263;font-weight:600'>View details &rarr;</a></p>" : '';
                $html = "<!DOCTYPE html><html><body style='margin:0;padding:0;background:#f1f5f9;font-family:Inter,Arial,sans-serif'>
<div style='max-width:560px;margin:0 auto;padding:28px 16px'>
<div style='background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.07)'>
<div style='background:linear-gradient(135deg,#0e1f3f,#1B3263);padding:18px 24px'>
  <span style='font-size:17px;font-weight:800;color:#fff'>{$appName}</span>
</div>
<div style='padding:24px'>
  <p style='font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#C9A84C;margin:0 0 8px'>{$type}</p>
  <h2 style='font-size:18px;font-weight:800;color:#0e1f3f;margin:0 0 12px'>" . htmlspecialchars($title) . "</h2>
  " . ($body ? "<p style='font-size:14px;color:#64748b;line-height:1.7;margin:0'>" . nl2br(htmlspecialchars($body)) . "</p>" : '') . "
  {$linkHtml}
</div>
<div style='background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 24px;text-align:center'>
  <p style='margin:0;font-size:12px;color:#94a3b8'>&copy; " . date('Y') . " {$appName}. Admin notification.</p>
</div>
</div></div></body></html>";
                appMail($admin['email'], "[{$appName}] {$title}", $html, $admin['name'] ?? '');
            }
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
