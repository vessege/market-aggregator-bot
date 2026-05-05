<?php
declare(strict_types=1);

use MarketBot\Core\Auth;
use MarketBot\Core\Database;

$config = require __DIR__ . '/_bootstrap.php';
Auth::requireLogin();

$pdo = Database::pdo();
$stats = [
    'users'      => (int) ($pdo->query('SELECT COUNT(*) AS c FROM users')->fetch()['c']           ?? 0),
    'products'   => (int) ($pdo->query('SELECT COUNT(*) AS c FROM products WHERE is_active=1')->fetch()['c'] ?? 0),
    'favorites'  => (int) ($pdo->query('SELECT COUNT(*) AS c FROM favorites')->fetch()['c']       ?? 0),
    'channels'   => (int) ($pdo->query('SELECT COUNT(*) AS c FROM subscription_channels WHERE is_active=1')->fetch()['c'] ?? 0),
    'broadcasts' => (int) ($pdo->query('SELECT COUNT(*) AS c FROM broadcasts')->fetch()['c']      ?? 0),
];

$bySource = $pdo->query("SELECT source, COUNT(*) AS c FROM products WHERE is_active=1 GROUP BY source")->fetchAll();
$lastRuns = $pdo->query("SELECT * FROM parser_runs ORDER BY id DESC LIMIT 8")->fetchAll();

ob_start();
?>
<div class="cards">
  <div class="kcard"><div class="kcard__num"><?= $stats['users'] ?></div><div class="kcard__lbl">Foydalanuvchi</div></div>
  <div class="kcard"><div class="kcard__num"><?= $stats['products'] ?></div><div class="kcard__lbl">Mahsulot</div></div>
  <div class="kcard"><div class="kcard__num"><?= $stats['favorites'] ?></div><div class="kcard__lbl">Sevimli</div></div>
  <div class="kcard"><div class="kcard__num"><?= $stats['channels'] ?></div><div class="kcard__lbl">Majburiy kanal</div></div>
  <div class="kcard"><div class="kcard__num"><?= $stats['broadcasts'] ?></div><div class="kcard__lbl">Xabar yuborilgan</div></div>
</div>

<h2>Manbalar bo'yicha mahsulotlar</h2>
<table class="table">
  <thead><tr><th>Manba</th><th>Sonі</th></tr></thead>
  <tbody>
    <?php if (!$bySource): ?>
      <tr><td colspan="2"><em>Hali mahsulot yo'q. Parserlarni ishga tushiring.</em></td></tr>
    <?php else: foreach ($bySource as $r): ?>
      <tr><td><?= htmlspecialchars((string) $r['source']) ?></td><td><?= (int) $r['c'] ?></td></tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<h2>So'nggi parser ishlari</h2>
<table class="table">
  <thead><tr><th>ID</th><th>Manba</th><th>Status</th><th>Yangi</th><th>Yangilangan</th><th>Vaqt</th></tr></thead>
  <tbody>
    <?php if (!$lastRuns): ?>
      <tr><td colspan="6"><em>Hech qachon parser ishga tushirilmagan.</em></td></tr>
    <?php else: foreach ($lastRuns as $r): ?>
      <tr>
        <td>#<?= (int) $r['id'] ?></td>
        <td><?= htmlspecialchars((string) $r['source']) ?></td>
        <td><span class="status status--<?= htmlspecialchars((string) $r['status']) ?>"><?= htmlspecialchars((string) $r['status']) ?></span></td>
        <td><?= (int) $r['inserted_count'] ?></td>
        <td><?= (int) $r['updated_count'] ?></td>
        <td><?= htmlspecialchars((string) $r['started_at']) ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>
<?php
$content = ob_get_clean();

$pageTitle = 'Dashboard';
$activeMenu = 'dashboard';
include __DIR__ . '/_layout.php';
