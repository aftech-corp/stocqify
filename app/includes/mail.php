<?php
/**
 * Application mailer helper.
 * Loads SMTP config from system_settings and sends via the socket-based Mailer class.
 *
 * Usage: appMail('user@example.com', 'Subject', '<p>HTML body</p>')
 */
function appMail(string $to, string $subject, string $htmlBody, string $toName = ''): bool {
    try {
        $rows = getDB()
            ->query("SELECT `key`,`value` FROM system_settings WHERE `key` LIKE 'smtp_%'")
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
    } catch (\Throwable $e) {
        return false;
    }

    if (empty($rows['smtp_host']) || empty($rows['smtp_username'])) return false;

    require_once __DIR__ . '/../lib/Mailer.php';

    $mailer = new \App\Lib\Mailer([
        'host'       => $rows['smtp_host'],
        'port'       => (int)($rows['smtp_port'] ?? 587),
        'username'   => $rows['smtp_username'],
        'password'   => $rows['smtp_password'] ?? '',
        'encryption' => $rows['smtp_encryption'] ?? 'tls',
        'fromEmail'  => $rows['smtp_from_email'] ?: $rows['smtp_username'],
        'fromName'   => $rows['smtp_from_name']  ?? (defined('APP_NAME') ? APP_NAME : 'Stocqify'),
    ]);

    return $mailer->sendHtml($to, $subject, $htmlBody, $toName);
}

/**
 * Returns a Mailer instance configured from DB (for callers needing the log).
 */
function appMailer(): ?\App\Lib\Mailer {
    try {
        $rows = getDB()
            ->query("SELECT `key`,`value` FROM system_settings WHERE `key` LIKE 'smtp_%'")
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
    } catch (\Throwable $e) {
        return null;
    }
    if (empty($rows['smtp_host'])) return null;
    require_once __DIR__ . '/../lib/Mailer.php';
    return new \App\Lib\Mailer([
        'host'       => $rows['smtp_host'],
        'port'       => (int)($rows['smtp_port'] ?? 587),
        'username'   => $rows['smtp_username'] ?? '',
        'password'   => $rows['smtp_password'] ?? '',
        'encryption' => $rows['smtp_encryption'] ?? 'tls',
        'fromEmail'  => $rows['smtp_from_email'] ?: ($rows['smtp_username'] ?? ''),
        'fromName'   => $rows['smtp_from_name']  ?? (defined('APP_NAME') ? APP_NAME : 'Stocqify'),
    ]);
}
