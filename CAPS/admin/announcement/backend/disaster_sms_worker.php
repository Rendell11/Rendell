<?php
/**
 * disaster_sms_worker.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Background SMS Worker for Disaster Alert Broadcasting
 *
 * HOW IT WORKS:
 *   1. process_disaster.php queues the broadcast (sms_queue job + one
 *      sms_recipient_logs row per resident, status 'pending') and redirects
 *      the admin back to the Announcement module immediately.
 *   2. process_disaster.php fires a non-blocking HTTP request to THIS file
 *      (sms_live_status.php also re-starts it if it ever stops).
 *   3. This worker replies "OK" at once, closes the connection and keeps
 *      running: it sends the queue one SMS at a time through the active SMS
 *      configuration, retries temporary failures (max SMS_MAX_ATTEMPTS),
 *      and updates the counters after every SMS so "View SMS Live" is live.
 *
 * ONE WORKER AT A TIME: a MySQL named lock (GET_LOCK) makes extra triggers
 * exit immediately, so a recipient is never sent twice by parallel workers
 * and the provider's rate limit is respected.
 *
 * Can also run from the command line / Windows Task Scheduler / cron as a
 * safety net:   php disaster_sms_worker.php
 *
 * SECURITY: only callable from localhost (127.0.0.1 / ::1) or the CLI.
 * COMPATIBLE WITH: XAMPP / mod_php / PHP-FPM — no extra extensions needed.
 * ─────────────────────────────────────────────────────────────────────────────
 */

ignore_user_abort(true);
set_time_limit(0);
ini_set('max_execution_time', '0');

$isCli = PHP_SAPI === 'cli';

// ── Only allow calls from localhost ──────────────────────────────────────────
if (!$isCli) {
    $caller_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($caller_ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit('Forbidden');
    }

    // ── Immediately send a 200 OK and close the HTTP connection ──────────────
    if (function_exists('fastcgi_finish_request')) {
        header('Content-Type: text/plain');
        header('Content-Length: 2');
        echo 'OK';
        fastcgi_finish_request();
    } else {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Connection: close');
        header('Content-Type: text/plain');
        header('Content-Length: 2');
        echo 'OK';
        flush();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

// ── Everything below runs in the background ─────────────────────────────────
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/sms_queue.php';

const SMS_WORKER_MAX_RUNTIME = 1500; // seconds; then hand over to a fresh worker

function worker_log(string $msg): void
{
    error_log('SMS Worker: ' . $msg);
}

try {
    sms_queue_ensure($pdo);
    if ((int) $pdo->query("SELECT GET_LOCK('caps_disaster_sms_worker', 0)")->fetchColumn() !== 1) {
        exit; // another worker is already sending
    }
} catch (Throwable $e) {
    worker_log('startup error — ' . $e->getMessage());
    exit;
}

$startedAt = time();
$handOver = false;

try {
    while (true) {
        if (time() - $startedAt > SMS_WORKER_MAX_RUNTIME) {
            $handOver = true;
            break;
        }

        // Oldest unfinished job
        $job = $pdo->query(
            "SELECT * FROM sms_queue WHERE status IN ('pending','processing') ORDER BY id ASC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            break; // queue empty
        }
        $jobId = (int) $job['id'];

        // Legacy job (queued before per-recipient rows existed): convert its JSON list once.
        if (empty($job['log_id'])) {
            $list = json_decode((string) $job['recipients'], true) ?: [];
            if ($list && !empty($job['alert_id'])) {
                $q = sms_enqueue_broadcast($pdo, (int) $job['alert_id'], (string) $job['message'], $list);
                // the new job created by sms_enqueue_broadcast replaces this one
            }
            $pdo->prepare("UPDATE sms_queue SET status = 'done', finished_at = NOW(), last_error = 'Converted to the per-recipient queue' WHERE id = ?")
                ->execute([$jobId]);
            continue;
        }

        $config = sms_active_config($pdo);
        if (!$config) {
            worker_log("no active SMS configuration — job {$jobId} stopped.");
            $pdo->prepare("UPDATE sms_recipient_logs SET status = 'failed', detail = 'No active SMS configuration', last_attempt_at = NOW(3)
                            WHERE log_id = ? AND status IN ('pending','retry','processing')")->execute([$job['log_id']]);
            $pdo->prepare("UPDATE sms_queue SET status = 'processing', last_error = 'No active SMS configuration. Set one in Settings → SMS Configuration.' WHERE id = ?")
                ->execute([$jobId]);
            sms_finalize_job($pdo, $job);
            continue;
        }

        if ($job['status'] === 'pending') {
            $pdo->prepare("UPDATE sms_queue SET status = 'processing', started_at = COALESCE(started_at, NOW()), heartbeat_at = NOW() WHERE id = ? AND status = 'pending'")
                ->execute([$jobId]);
            $pdo->prepare("UPDATE sms_logs SET status = 'Sending' WHERE LogID = ?")->execute([$job['log_id']]);
            worker_log("starting job {$jobId} (log {$job['log_id']}) — {$job['total']} recipients | config: " . ($config['configuration_name'] ?? '?'));
        }

        // A previous worker died in the middle of a request: we cannot know if that SMS went out,
        // so it is NOT re-sent automatically (no duplicates) — it is marked failed for follow-up.
        $pdo->prepare("UPDATE sms_recipient_logs
                          SET status = 'failed', detail = 'Sending was interrupted — result unknown, please verify with the resident'
                        WHERE log_id = ? AND status = 'processing' AND last_attempt_at < NOW(3) - INTERVAL 2 MINUTE")
            ->execute([$job['log_id']]);

        $message = (string) $job['message'];
        $cancelled = false;

        // ── Send in batches ──────────────────────────────────────────────────
        while (true) {
            $batch = $pdo->prepare(
                "SELECT id, resident_id, resident_name, contact_number, attempts FROM sms_recipient_logs
                  WHERE log_id = ? AND status IN ('pending','retry')
                    AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                  ORDER BY attempts ASC, id ASC
                  LIMIT " . SMS_BATCH_SIZE
            );
            $batch->execute([$job['log_id']]);
            $rows = $batch->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            foreach ($rows as $r) {
                // Cancelled from View SMS Live?
                $st = $pdo->prepare("SELECT status FROM sms_queue WHERE id = ?");
                $st->execute([$jobId]);
                if ($st->fetchColumn() === 'cancelled') {
                    $cancelled = true;
                    break 2;
                }

                // Claim the row — only one sender can move it to 'processing'
                $claim = $pdo->prepare("UPDATE sms_recipient_logs
                                           SET status = 'processing', attempts = attempts + 1, last_attempt_at = NOW(3), next_attempt_at = NULL
                                         WHERE id = ? AND status IN ('pending','retry')");
                $claim->execute([$r['id']]);
                if ($claim->rowCount() !== 1) {
                    continue;
                }
                $attempt = (int) $r['attempts'] + 1;
                $to = sms_normalize_number($r['contact_number']);

                if (!$to) {
                    $pdo->prepare("UPDATE sms_recipient_logs SET status = 'invalid', detail = ? WHERE id = ?")
                        ->execute(['Number too short: ' . $r['contact_number'], $r['id']]);
                } else {
                    $res = sms_gateway_send($config, $to, $message);
                    if ($res['ok']) {
                        $pdo->prepare("UPDATE sms_recipient_logs
                                          SET status = 'sent', delivery = 'pending', message_id = ?, detail = ?, sent_at = NOW(), last_attempt_at = NOW(3)
                                        WHERE id = ?")
                            ->execute([$res['message_id'] ? mb_substr((string) $res['message_id'], 0, 100) : null, $res['detail'], $r['id']]);
                    } elseif ($res['temporary'] && $attempt < SMS_MAX_ATTEMPTS) {
                        // PENDING → RETRY → SENT / FAILED
                        $pdo->prepare("UPDATE sms_recipient_logs
                                          SET status = 'retry', detail = ?, last_attempt_at = NOW(3),
                                              next_attempt_at = NOW() + INTERVAL ? SECOND
                                        WHERE id = ?")
                            ->execute([mb_substr($res['detail'] . " (attempt {$attempt} of " . SMS_MAX_ATTEMPTS . ')', 0, 255), SMS_RETRY_DELAY_SECONDS * $attempt, $r['id']]);
                    } else {
                        $pdo->prepare("UPDATE sms_recipient_logs SET status = 'failed', detail = ?, last_attempt_at = NOW(3) WHERE id = ?")
                            ->execute([mb_substr($res['detail'] . ($res['temporary'] ? ' — gave up after ' . $attempt . ' attempts' : ''), 0, 255), $r['id']]);
                    }
                }

                // Counters for the SMS Live cards (cheap: indexed count on one broadcast)
                $t = sms_job_counts($pdo, (int) $job['log_id']);
                $pdo->prepare("UPDATE sms_queue SET sent = ?, failed = ?, heartbeat_at = NOW() WHERE id = ?")
                    ->execute([$t['sent'], $t['failed'], $jobId]);
                $pdo->prepare("UPDATE sms_logs SET sent_count = ? WHERE LogID = ?")->execute([$t['sent'], $job['log_id']]);

                usleep(SMS_SEND_DELAY_MS * 1000);
            }
        }

        if ($cancelled) {
            $pdo->prepare("UPDATE sms_recipient_logs SET status = 'cancelled', detail = 'Cancelled by an administrator'
                            WHERE log_id = ? AND status IN ('pending','retry')")->execute([$job['log_id']]);
            $job['status'] = 'cancelled';
            sms_finalize_job($pdo, $job);
            worker_log("job {$jobId} cancelled.");
            continue;
        }

        // Only retries that are not due yet are left → wait for the earliest one
        $next = $pdo->prepare("SELECT GREATEST(1, TIMESTAMPDIFF(SECOND, NOW(), MIN(next_attempt_at))) FROM sms_recipient_logs
                                WHERE log_id = ? AND status = 'retry'");
        $next->execute([$job['log_id']]);
        $wait = $next->fetchColumn();
        if ($wait !== null && $wait !== false) {
            $pdo->prepare("UPDATE sms_queue SET heartbeat_at = NOW() WHERE id = ?")->execute([$jobId]);
            sleep(min(10, max(1, (int) $wait))); // short sleeps keep the heartbeat fresh
            continue;
        }

        $t = sms_finalize_job($pdo, $job);
        worker_log("job {$jobId} finished — sent {$t['sent']}, failed {$t['failed']}.");
    }
} catch (Throwable $e) {
    worker_log('error — ' . $e->getMessage());
    try {
        $pdo->prepare("UPDATE sms_queue SET last_error = ? WHERE status = 'processing'")->execute([mb_substr($e->getMessage(), 0, 255)]);
    } catch (Throwable $ignored) {
    }
    // no automatic restart on errors: sms_live_status.php restarts a stalled worker while someone watches
}

$pdo->query("SELECT RELEASE_LOCK('caps_disaster_sms_worker')");

// Long queue: continue in a fresh request so one PHP process never runs forever.
if ($handOver && !$isCli) {
    sleep(1);
    sms_trigger_worker();
}
