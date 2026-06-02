<?php

declare(strict_types=1);

namespace App\Security;

/** Per-key request limiter backed by local files; no database, Redis, or extensions. */
final class FilesystemRateLimiter
{
    private const WINDOWS = ['minute' => 60, 'hour' => 3600, 'day' => 86400];

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Records one hit for the key and reports whether every limit is still satisfied.
     *
     * @param array<string, int> $limits window name (minute|hour|day) => maximum requests
     */
    public function allow(string $key, array $limits): bool
    {
        if ($limits === []) {
            return true;
        }
        $this->ensureDirectory();
        $this->maybeCleanup();
        $file = $this->directory . '/' . hash('sha256', $key) . '.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // Fail open: a broken storage path must not block legitimate callers.
            return true;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }
            $state = json_decode((string) stream_get_contents($handle), true);
            $state = is_array($state) ? $state : [];
            $now = time();
            $within = true;
            foreach ($limits as $window => $max) {
                $seconds = self::WINDOWS[$window] ?? null;
                if ($seconds === null || $max <= 0) {
                    continue;
                }
                $bucket = intdiv($now, $seconds);
                $entry = $state[$window] ?? null;
                $count = (is_array($entry) && ($entry['bucket'] ?? null) === $bucket) ? (int) $entry['count'] + 1 : 1;
                $state[$window] = ['bucket' => $bucket, 'count' => $count];
                if ($count > $max) {
                    $within = false;
                }
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);

            return $within;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0770, true);
        }
    }

    private function maybeCleanup(): void
    {
        try {
            if (random_int(1, 200) !== 1) {
                return;
            }
        } catch (\Throwable) {
            // Fail open if randomness is unavailable.
            return;
        }
        $cutoff = time() - self::WINDOWS['day'];
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            if ((int) @filemtime($path) < $cutoff) {
                @unlink($path);
            }
        }
    }
}
