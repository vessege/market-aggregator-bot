<?php
declare(strict_types=1);

namespace MarketBot\Core;

final class Bootstrap
{
    /** @return array<string,mixed> */
    public static function init(): array
    {
        $root = dirname(__DIR__, 2);
        if (file_exists($root . '/vendor/autoload.php')) {
            require_once $root . '/vendor/autoload.php';
        } else {
            self::registerAutoloader($root);
        }

        /** @var array<string,mixed> $config */
        $config = require $root . '/config/config.php';

        // Production: hide errors from end users, but always log them.
        if (($config['app']['env'] ?? 'production') === 'production') {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        } else {
            ini_set('display_errors', '1');
            ini_set('log_errors', '1');
            error_reporting(E_ALL);
        }

        Logger::init((string) $config['paths']['logs']);
        Database::init($config['db']);

        return $config;
    }

    private static function registerAutoloader(string $root): void
    {
        spl_autoload_register(static function (string $class) use ($root): void {
            $prefix = 'MarketBot\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $path = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        });
    }
}
