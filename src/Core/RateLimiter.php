<?php
declare(strict_types=1);

namespace MarketBot\Core;

/**
 * File-based per-IP rate limiter.
 *
 * Tracks {bucket}{ip} hits inside a JSON file in storage/ratelimit/.
 * Designed to survive shared hosting where no Redis/APCu is available.
 *
 * Usage:
 *   if (!RateLimiter::allow('api:products', $ip, limit: 60, windowSec: 60)) {
 *       http_response_code(429);
 *       echo json_encode(['ok' => false, 'error' => 'rate_limited']);
 *       exit;
 *   }
 *
 * Tunables come from .env (RATE_LIMIT_API_RPM, RATE_LIMIT_LIVE_RPM).
 */
final class RateLimiter
{
    /** Returns true if the request is allowed; false if it should be rejected. */
    public static function allow(string $bucket, string $ip, int $limit, int $windowSec = 60): bool
    {
        if ($limit <= 0) {
            return true; // 0 means "disabled"
        }
        $dir = self::dir();
        if ($dir === null) {
            return true; // can't write — fail-open rather than block legit users.
        }
        $key  = preg_replace('/[^a-z0-9_:\-\.]/i', '_', $bucket . '|' . $ip);
        $file = $dir . '/' . substr(md5($key), 0, 16) . '.json';
        $now  = time();
        $fp   = @fopen($file, 'c+');
        if ($fp === false) {
            return true;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return true;
            }
            $raw  = stream_get_contents($fp);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (!is_array($data) || ($data['window_start'] ?? 0) + $windowSec < $now) {
                $data = ['window_start' => $now, 'count' => 0];
            }
            $data['count']++;
            $allowed = $data['count'] <= $limit;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode($data));
            fflush($fp);
            flock($fp, LOCK_UN);
            return $allowed;
        } finally {
            fclose($fp);
        }
    }

    /** Best-effort: classic proxy headers + REMOTE_ADDR. */
    public static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', (string) $_SERVER[$h])[0]);
                if ($ip !== '') return $ip;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private static function dir(): ?string
    {
        $root = realpath(__DIR__ . '/../..');
        if ($root === false) {
            return null;
        }
        $dir = $root . '/storage/ratelimit';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        // GC: occasionally purge old files (1% chance per call).
        if (random_int(1, 100) === 1) {
            $now = time();
            foreach (glob($dir . '/*.json') ?: [] as $f) {
                if (@filemtime($f) + 3600 < $now) @unlink($f);
            }
        }
        return $dir;
    }
}
