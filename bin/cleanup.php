<?php

/**
 * @file cleanup.php
 *
 * Deletes expired nonce, rate-limit, and audit records as a scheduled maintenance task.
 *
 * Creation Date: 2026-06-02
 * Inputs: Command-line options, environment variables, and runtime configuration files.
 * Outputs: Writes operational status to the console and updates gateway state as described below.
 * Usage: php bin/cleanup.php
 * Author: Cris Magalang
 * Code Assistants and generators: Codex and Claude code
 */
declare(strict_types=1);

/**
 * Retention/cleanup utility for the DBO gateway.
 *
 * Run from cron, e.g. daily:
 *   php bin/cleanup.php
 *
 * Deletes expired nonces, stale rate-limit buckets, audit logs older than the
 * configured retention window, and orphaned filesystem rate-limit counters.
 */

use App\Database\Connection;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

$securityFile = dirname(__DIR__) . '/config/security.php';
$security = is_readable($securityFile) ? (array) require $securityFile : [];
$retentionDays = (int) ($security['audit']['retention_days'] ?? 90);
$storageDir = $security['pre_auth_rate_limit']['storage_dir'] ?? (sys_get_temp_dir() . '/dbo_gateway_ratelimit');

if (!is_string($storageDir)) {
    throw new \RuntimeException('pre_auth_rate_limit.storage_dir must be a string when set');
}

$database = Connection::getInstance();
$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

// Remove database records that no longer participate in request enforcement.
$nonces = $database->prepare('DELETE FROM `api_nonces` WHERE `expires_at` < :now');
$nonces->execute(['now' => $now]);
fwrite(STDOUT, sprintf("Deleted %d expired nonce(s).\n", $nonces->rowCount()));

$rateLimits = $database->prepare('DELETE FROM `api_rate_limits` WHERE `window_end` < :now');
$rateLimits->execute(['now' => $now]);
fwrite(STDOUT, sprintf("Deleted %d stale rate-limit bucket(s).\n", $rateLimits->rowCount()));

if ($retentionDays > 0) {
    $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . $retentionDays . ' days')->format('Y-m-d H:i:s');
    $audit = $database->prepare('DELETE FROM `api_audit_logs` WHERE `created_at` < :cutoff');
    $audit->execute(['cutoff' => $cutoff]);
    fwrite(STDOUT, sprintf("Deleted %d audit log(s) older than %d day(s).\n", $audit->rowCount(), $retentionDays));
}

// File buckets can survive process interruptions; prune counters older than one day.
$removed = 0;
$fileCutoff = time() - 86400;
foreach (glob(rtrim((string) $storageDir, '/') . '/*.json') ?: [] as $path) {
    if ((int) @filemtime($path) < $fileCutoff && @unlink($path)) {
        $removed++;
    }
}
fwrite(STDOUT, sprintf("Removed %d orphaned rate-limit file(s).\n", $removed));
