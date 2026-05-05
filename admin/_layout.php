<?php
declare(strict_types=1);

use MarketBot\Core\Auth;

if (!isset($pageTitle)) $pageTitle = 'Admin';
$activeMenu = $activeMenu ?? '';
$user = Auth::user();
?>
<!DOCTYPE html>
<html lang="uz">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle) ?> — Market Aggregator Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="sidebar__brand">🛒 MarketBot</div>
    <nav class="sidebar__nav">
      <a href="index.php"      class="<?= $activeMenu === 'dashboard' ? 'is-active' : '' ?>">📊 Dashboard</a>
      <a href="parsers.php"    class="<?= $activeMenu === 'parsers'   ? 'is-active' : '' ?>">🔄 Parserlar</a>
      <a href="products.php"   class="<?= $activeMenu === 'products'  ? 'is-active' : '' ?>">📦 Mahsulotlar</a>
      <a href="broadcast.php"  class="<?= $activeMenu === 'broadcast' ? 'is-active' : '' ?>">📣 Xabar yuborish</a>
      <a href="channels.php"   class="<?= $activeMenu === 'channels'  ? 'is-active' : '' ?>">📌 Majburiy obuna</a>
      <a href="users.php"      class="<?= $activeMenu === 'users'     ? 'is-active' : '' ?>">👥 Foydalanuvchilar</a>
    </nav>
    <div class="sidebar__footer">
      <span><?= htmlspecialchars((string) ($user['username'] ?? '')) ?></span>
      <a href="logout.php" class="link-danger">Chiqish</a>
    </div>
  </aside>
  <main class="main">
    <header class="topbar">
      <h1><?= htmlspecialchars($pageTitle) ?></h1>
    </header>
    <section class="content">
      <?php if (!empty($_SESSION['flash'])): ?>
        <div class="flash flash--<?= htmlspecialchars((string) $_SESSION['flash']['type']) ?>">
          <?= htmlspecialchars((string) $_SESSION['flash']['message']) ?>
        </div>
        <?php $_SESSION['flash'] = null; ?>
      <?php endif; ?>
      <?= $content ?? '' ?>
    </section>
  </main>
</div>
</body>
</html>
