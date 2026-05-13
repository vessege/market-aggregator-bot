<?php
declare(strict_types=1);

use MarketBot\Core\Auth;
use MarketBot\Core\Csrf;

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
    <div class="sidebar__brand">🛒 MarketCompare</div>
    <nav class="sidebar__nav">
      <a href="index.php"      class="<?= $activeMenu === 'dashboard' ? 'is-active' : '' ?>">📊 Dashboard</a>
      <a href="parsers.php"    class="<?= $activeMenu === 'parsers'   ? 'is-active' : '' ?>">🔄 Parserlar</a>
      <a href="markets.php"    class="<?= $activeMenu === 'markets'   ? 'is-active' : '' ?>">🏬 Marketlar</a>
      <a href="products.php"   class="<?= $activeMenu === 'products'  ? 'is-active' : '' ?>">📦 Mahsulotlar</a>
      <a href="keywords.php"   class="<?= $activeMenu === 'keywords'  ? 'is-active' : '' ?>">🔥 Hot Keywords</a>
      <a href="alerts.php"     class="<?= $activeMenu === 'alerts'    ? 'is-active' : '' ?>">🔔 Ogohlantirishlar</a>
      <a href="settings.php"   class="<?= $activeMenu === 'settings'  ? 'is-active' : '' ?>">⚙️ Sozlamalar</a>
    </nav>
    <div class="sidebar__footer">
      <span><?= htmlspecialchars((string) ($user['username'] ?? '')) ?></span>
      <form method="post" action="logout.php" class="logout-form">
        <?= Csrf::field() ?>
        <button type="submit" class="link-danger">Chiqish</button>
      </form>
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
