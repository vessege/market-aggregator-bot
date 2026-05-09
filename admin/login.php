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

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        $error = 'CSRF token xato.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $stmt = Database::pdo()->prepare('SELECT id, username, password_hash FROM admins WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($password, (string) $admin['password_hash'])) {
            Auth::login((int) $admin['id'], (string) $admin['username']);
            header('Location: index.php');
            exit;
        }
        $error = 'Login yoki parol noto\'g\'ri.';
    }
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Kirish — MarketCompare</title>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="login-page">
  <div class="login-card">
    <div class="login-card__logo">🛒</div>
    <h1>MarketCompare Admin</h1>
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
