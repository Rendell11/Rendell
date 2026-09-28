<?php
/**
 * user/backend/push_worker.php — sends waiting push notifications (push_lib.php).
 *
 * Run it every minute with Windows Task Scheduler (XAMPP):
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\CAPS\user\backend\push_worker.php
 * Command line only (a browser request gets 403).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run from the command line.');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/push_lib.php';

if (!push_config()) {
    fwrite(STDOUT, "Push is off: user/backend/private/firebase_service_account.json not found.\n");
    exit(0);
}
@touch(PUSH_LOCK_FILE);
$n = push_pending(db(), 50);
fwrite(STDOUT, date('Y-m-d H:i:s') . " sent to {$n} device(s)\n");
