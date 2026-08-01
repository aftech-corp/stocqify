<?php
namespace App\Lib;

/**
 * Minimal socket-based SMTP mailer — no external dependencies.
 * Supports TLS (STARTTLS), SSL, and plain connections with AUTH LOGIN.
 */
class Mailer {
    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $enc;        // tls | ssl | none
    private string $fromEmail;
    private string $fromName;
    private        $sock    = null;
    private array  $log     = [];
    private int    $timeout = 20;

    public function __construct(array $c) {
        $this->host      = $c['host']       ?? '';
        $this->port      = (int)($c['port'] ?? 587);
        $this->user      = $c['username']   ?? '';
        $this->pass      = $c['password']   ?? '';
        $this->enc       = strtolower($c['encryption'] ?? 'tls');
        $this->fromEmail = $c['fromEmail']  ?? '';
        $this->fromName  = $c['fromName']   ?? '';
    }

    public function sendHtml(
        string $to,
        string $subject,
        string $html,
        string $toName    = '',
        string $plainText = ''
    ): bool {
        try {
            if (!$this->connect()) return false;

            $this->smtpCmd("MAIL FROM:<{$this->fromEmail}>");
            $this->smtpCmd("RCPT TO:<{$to}>");

            $resp = $this->smtpCmd("DATA");
            if (!str_starts_with(trim($resp), '354')) {
                throw new \RuntimeException("Expected 354 after DATA, got: {$resp}");
            }

            $mime = $this->buildMime($to, $toName, $subject, $html, $plainText ?: strip_tags($html));
            fwrite($this->sock, $mime . "\r\n.\r\n");
            $ack = $this->smtpRead();
            $this->log[] = ">> [body+endmarker] << " . trim($ack);
            if (!str_starts_with(trim($ack), '250')) {
                throw new \RuntimeException("DATA body rejected: {$ack}");
            }

            $this->smtpCmd("QUIT");
            @fclose($this->sock);
            $this->sock = null;
            return true;

        } catch (\Throwable $e) {
            $this->log[] = "ERROR: " . $e->getMessage();
            if ($this->sock) { @fclose($this->sock); $this->sock = null; }
            return false;
        }
    }

    public function getLog(): array { return $this->log; }

    // ── Private: connection & protocol ─────────────────────────

    private function connect(): bool {
        $addr = ($this->enc === 'ssl')
            ? "ssl://{$this->host}:{$this->port}"
            : "{$this->host}:{$this->port}";

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
                'allow_self_signed'=> true,
            ]
        ]);

        $errno = 0; $errstr = '';
        $this->sock = @stream_socket_client(
            $addr, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$this->sock) {
            $this->log[] = "CONNECT FAILED [{$addr}]: {$errstr} ({$errno})";
            return false;
        }
        stream_set_timeout($this->sock, $this->timeout);

        $greet = $this->smtpRead();
        $this->log[] = "<< " . trim($greet);
        if (!str_starts_with(trim($greet), '220')) {
            $this->log[] = "Unexpected greeting";
            return false;
        }

        $this->smtpCmd("EHLO " . ($this->safeHostname()));

        if ($this->enc === 'tls') {
            $stls = $this->smtpCmd("STARTTLS");
            if (!str_starts_with(trim($stls), '220')) {
                $this->log[] = "STARTTLS rejected: " . trim($stls);
                return false;
            }
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->log[] = "TLS crypto upgrade failed";
                return false;
            }
            $this->smtpCmd("EHLO " . $this->safeHostname());
        }

        if ($this->user) {
            $this->smtpCmd("AUTH LOGIN");
            $this->smtpCmd(base64_encode($this->user));
            $auth = $this->smtpCmd(base64_encode($this->pass));
            if (!str_starts_with(trim($auth), '235')) {
                $this->log[] = "AUTH LOGIN failed: " . trim($auth);
                return false;
            }
            $this->log[] = "AUTH LOGIN: OK";
        }

        return true;
    }

    private function smtpCmd(string $cmd): string {
        fwrite($this->sock, $cmd . "\r\n");
        $resp = $this->smtpRead();
        // Mask base64 credentials in log
        $display = (strlen($cmd) > 40 && !preg_match('/^[A-Z]/', $cmd))
            ? '[credentials]'
            : $cmd;
        $this->log[] = ">> {$display} << " . trim($resp);
        return $resp;
    }

    private function smtpRead(): string {
        $resp = '';
        while (!feof($this->sock)) {
            $line = fgets($this->sock, 512);
            if ($line === false) break;
            $resp .= $line;
            // Multi-line: continuation lines have '-' at position 3; last line has ' '
            if (strlen($line) >= 4 && $line[3] === ' ') break;
            if (strlen($line) < 4) break;
        }
        return $resp;
    }

    private function buildMime(string $to, string $toName, string $subject, string $html, string $plain): string {
        $b    = '----=_Part_' . md5(uniqid('', true));
        $from = $this->fromName
            ? '=?UTF-8?B?' . base64_encode($this->fromName) . '?= <' . $this->fromEmail . '>'
            : $this->fromEmail;
        $toH  = $toName
            ? '=?UTF-8?B?' . base64_encode($toName) . '?= <' . $to . '>'
            : $to;
        $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $m  = "Date: " . date('r') . "\r\n";
        $m .= "From: {$from}\r\n";
        $m .= "To: {$toH}\r\n";
        $m .= "Subject: {$subj}\r\n";
        $m .= "MIME-Version: 1.0\r\n";
        $m .= "Content-Type: multipart/alternative; boundary=\"{$b}\"\r\n";
        $m .= "X-Mailer: SaloneBizness/1.0\r\n";
        $m .= "\r\n";
        $m .= "--{$b}\r\n";
        $m .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $m .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $m .= chunk_split(base64_encode($plain));
        $m .= "--{$b}\r\n";
        $m .= "Content-Type: text/html; charset=UTF-8\r\n";
        $m .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $m .= chunk_split(base64_encode($html));
        $m .= "--{$b}--\r\n";
        return $m;
    }

    private function safeHostname(): string {
        $h = @gethostname();
        return ($h && filter_var($h, FILTER_VALIDATE_DOMAIN)) ? $h : 'localhost';
    }
}
