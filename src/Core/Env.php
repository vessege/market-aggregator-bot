<?php
declare(strict_types=1);

namespace MarketBot\Core;

final class Env
{
    /** @var array<string,string> */
    private static array $cache = [];
    private static int $cachedMtime = 0;
    private static string $cachedPath = '';

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        // Reload .env whenever the file changes on disk. This lets operators
        // rotate secrets (Uzum JWT, etc.) without restarting PHP-FPM — the
        // long-lived workers automatically pick up the new values on the next
        // request after the file is saved.
        clearstatcache(true, $path);
        $mtime = (int) @filemtime($path);
        if (self::$cachedPath === $path && self::$cachedMtime === $mtime && self::$cache !== []) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        $fresh = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));

            if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            $fresh[$key] = $value;
            // Force-override process env vars so an edited .env wins over any
            // stale value cached by a long-lived PHP-FPM worker. Code that
            // reads `getenv(...)` directly will now see the latest value.
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        self::$cache = $fresh;
        self::$cachedPath = $path;
        self::$cachedMtime = $mtime;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return $default;
    }
}
