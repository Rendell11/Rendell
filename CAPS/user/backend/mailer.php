<?php
/**
 * user/backend/mailer.php — send email from the resident app backend.
 *
 * Uses the SAME Gmail account the admin already set up for the access
 * requests (PHPMailer from CAPS/vendor + an smtp_config.php that returns
 * ['host','port','secure','user','pass','from_name']). The first file found:
 *   1. user/backend/private/smtp_config.php        (blocked from the web)
 *   2. admin/residents/frontend/smtp_config.php    (the admin's)
 * Copy admin/residents/frontend/smtp_config.sample.php to one of them and put
 * a Gmail App Password in it. Never commit it.
 *
 * Test from a terminal:  php mailer.php you@gmail.com
 */

declare(strict_types=1);

function mailer_config(): ?array
{
    static $cfg = false;
    if ($cfg !== false) return $cfg;
    $cfg = null;
    foreach ([
        __DIR__ . '/private/smtp_config.php',
        __DIR__ . '/../../admin/residents/frontend/smtp_config.php',
    ] as $file) {
        if (!is_file($file)) continue;
        $c = include $file;
        if (!is_array($c)) continue;
        $user = trim((string) ($c['user'] ?? ''));
        $pass = trim((string) ($c['pass'] ?? ''));
        if ($user === '' || $pass === '' || stripos($pass, 'PASTE_') !== false) continue;
        $cfg = [
            'host'      => (string) ($c['host'] ?? 'smtp.gmail.com'),
            'port'      => (int) ($c['port'] ?? 587),
            'secure'    => strtolower((string) ($c['secure'] ?? 'tls')),
            'user'      => $user,
            'pass'      => $pass,
            'from_name' => (string) ($c['from_name'] ?? 'Barangay Biñang 2nd'),
        ];
        break;
    }
    return $cfg;
}

function mailer_load(): bool
{
    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) return true;
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (is_file($autoload)) require_once $autoload;
    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) return true;
    foreach ([__DIR__ . '/../../vendor/phpmailer/phpmailer/src', __DIR__ . '/../../login/vendor/phpmailer/phpmailer/src'] as $src) {
        if (is_file("$src/PHPMailer.php")) {
            require_once "$src/Exception.php";
            require_once "$src/PHPMailer.php";
            require_once "$src/SMTP.php";
            return true;
        }
    }
    return false;
}

/** True when an email can be sent (config + PHPMailer present). */
function mailer_ready(): bool
{
    return mailer_config() !== null && mailer_load();
}

/** Send one HTML email. Returns true when the SMTP server accepted it. */
function send_mail(string $to, string $toName, string $subject, string $html, ?string &$error = null): bool
{
    $c = mailer_config();
    if ($c === null) { $error = 'SMTP is not configured'; return false; }
    if (!mailer_load()) { $error = 'PHPMailer not found (CAPS/vendor)'; return false; }
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host = $c['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $c['user'];
        $mail->Password = $c['pass'];
        if ($c['secure'] === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($c['secure'] === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        $mail->Port = $c['port'];
        $mail->Timeout = 20;
        $mail->setFrom($c['user'], $c['from_name']);
        $mail->addAddress($to, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>|</h\d>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $mail->send();
        return true;
    } catch (\Throwable $e) {
        $error = $mail->ErrorInfo ?: $e->getMessage();
        error_log('[mailer] ' . $error);
        return false;
    }
}

// CLI test: php mailer.php someone@example.com
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $to = $argv[1] ?? '';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) exit("Usage: php mailer.php you@example.com\n");
    if (mailer_config() === null) exit("No smtp_config.php with a real App Password (see the top of this file).\n");
    $err = null;
    $ok = send_mail($to, '', 'Test email — Barangay Biñang 2nd', '<p>Gumagana ang email ng resident app.</p>', $err);
    echo $ok ? "Sent to $to\n" : "FAILED: $err\n";
}
