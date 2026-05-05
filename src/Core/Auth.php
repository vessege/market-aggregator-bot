<?php
declare(strict_types=1);

namespace MarketBot\Core;

final class Auth
{
    public static function start(string $sessionName): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name($sessionName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function login(int $adminId, string $username): void
    {
        $_SESSION['admin'] = [
            'id'       => $adminId,
            'username' => $username,
            'login_at' => time(),
        ];
        session_regenerate_id(true);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['admin']['id']);
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        return $_SESSION['admin'] ?? null;
    }

    public static function requireLogin(string $redirectTo = 'login.php'): void
    {
        if (!self::check()) {
            header('Location: ' . $redirectTo);
            exit;
        }
    }
}
