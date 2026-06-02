<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use PDO;
use PDOException;

/** MySQL/MariaDB-backed fixed-window request limiter. */
final class RateLimitService
{
    public function __construct(private readonly PDO $database, private readonly int $maxRequestsPerMinute)
    {
    }

    public function consume(int $clientId, ?string $ipAddress): void
    {
        $windowStart = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $windowStart = $windowStart->setTime((int) $windowStart->format('H'), (int) $windowStart->format('i'), 0);
        $bucket = $windowStart->format('YmdHi') . ':' . substr(hash('sha256', $ipAddress ?? ''), 0, 16);
        // Best-effort cleanup to prevent unbounded table growth.
        $cleanup = $this->database->prepare('DELETE FROM `api_rate_limits` WHERE `client_id` = :client_id AND `window_end` < :cutoff');
        $cleanup->execute(['client_id' => $clientId, 'cutoff' => $windowStart->modify('-2 minutes')->format('Y-m-d H:i:s')]);
        try {
            $insert = $this->database->prepare(
                'INSERT INTO `api_rate_limits` (`client_id`, `bucket_key`, `request_count`, `window_start`, `window_end`) '
                . 'VALUES (:client_id, :bucket, 1, :window_start, :window_end)'
            );
            $insert->execute([
                'client_id' => $clientId,
                'bucket' => $bucket,
                'window_start' => $windowStart->format('Y-m-d H:i:s'),
                'window_end' => $windowStart->modify('+1 minute')->format('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $increment = $this->database->prepare(
                'UPDATE `api_rate_limits` SET `request_count` = `request_count` + 1 '
                . 'WHERE `client_id` = :client_id AND `bucket_key` = :bucket'
            );
            $increment->execute(['client_id' => $clientId, 'bucket' => $bucket]);
        }
        $count = $this->database->prepare('SELECT `request_count` FROM `api_rate_limits` WHERE `client_id` = :client_id AND `bucket_key` = :bucket');
        $count->execute(['client_id' => $clientId, 'bucket' => $bucket]);
        if ((int) $count->fetchColumn() > $this->maxRequestsPerMinute) {
            throw new ApiException('RATE_LIMIT_EXCEEDED', 'Rate limit exceeded', 429);
        }
    }
}
