<?php
declare(strict_types=1);

use MarketBot\Core\Auth;
use MarketBot\Core\Csrf;
use MarketBot\Core\Database;

$config = require __DIR__ . '/_bootstrap.php';

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MINUTES = 15;

function login_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function login_attempts_recent(string $ip): int
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_MINUTES * 60);
    $stmt = Database::pdo()->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND attempted_at >= :since'
    );
    $stmt->execute(['ip' => $ip, 'since' => $since]);
    return (int) $stmt->fetchColumn();
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        $error = 'CSRF token xato.';
    } elseif (login_attempts_recent(login_ip()) >= LOGIN_MAX_ATTEMPTS) {
        $error = 'Juda ko\'p urinish. ' . LOGIN_WINDOW_MINUTES . ' daqiqadan keyin qayta urinib ko\'ring.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $stmt = Database::pdo()->prepare('SELECT id, username, password_hash FROM admins WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($password, (string) $admin['password_hash'])) {
            Database::pdo()->prepare('DELETE FROM login_attempts WHERE ip = :ip')->execute(['ip' => login_ip()]);
            Auth::login((int) $admin['id'], (string) $admin['username']);
            header('Location: index.php');
            exit;
        }
        Database::pdo()->prepare(
            'INSERT INTO login_attempts (ip, username, attempted_at) VALUES (:ip, :u, :now)'
        )->execute(['ip' => login_ip(), 'u' => mb_substr($username, 0, 64), 'now' => date('Y-m-d H:i:s')]);
        $error = 'Login yoki parol noto\'g\'ri.';
    }
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Kirish — MarketBot</title>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="login-page">
  <div class="login-card">
    <div class="login-card__logo">🛒</div>
    <h1>MarketBot Admin</h1>
    <?php if ($error): ?><div class="flash flash--error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <?= Csrf::field() ?>
      <label>Login
        <input type="text" name="username" required autofocus value="<?= htmlspecialchars((string) ($_POST['username'] ?? '')) ?>">
      </label>
      <label>Parol
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="btn btn--primary btn--block">Kirish</button>
    </form>
  </div>
</body>
</html>
