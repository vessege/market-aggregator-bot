<?php
declare(strict_types=1);

namespace MarketBot\Core;

final class Logger
{
    private static string $dir = '';

    public static function init(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        self::$dir = $dir;
    }

    public static function info(string $channel, string $message, array $ctx = []): void
    {
        self::write('INFO', $channel, $message, $ctx);
    }

    public static function error(string $channel, string $message, array $ctx = []): void
    {
        self::write('ERROR', $channel, $message, $ctx);
    }

    private static function write(string $level, string $channel, string $message, array $ctx): void
    {
        $dir = self::$dir !== '' ? self::$dir : sys_get_temp_dir();
        $file = $dir . '/' . $channel . '-' . date('Y-m-d') . '.log';
        $line = sprintf(
            "[%s] %s %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
