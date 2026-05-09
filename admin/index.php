<?php
declare(strict_types=1);

use MarketBot\Core\Auth;
use MarketBot\Core\Database;

$config = require __DIR__ . '/_bootstrap.php';
Auth::requireLogin();

$pdo = Database::pdo();

$count = static function (string $sql) use ($pdo): int {
    try {
        $row = $pdo->query($sql)->fetch();
        return (int) ($row['c'] ?? 0);
    } catch (\Throwable) {
        return 0;
    }
};

$stats = [
    'products'  => $count('SELECT COUNT(*) AS c FROM products WHERE is_active=1'),
    'sources'   => $count('SELECT COUNT(*) AS c FROM (SELECT DISTINCT source FROM products) AS s'),
    'markets'   => $count('SELECT COUNT(*) AS c FROM dynamic_sources WHERE is_active=1'),
    'runs'      => $count('SELECT COUNT(*) AS c FROM parser_runs'),
];

$bySource = $pdo->query("SELECT source, COUNT(*) AS c FROM products WHERE is_active=1 GROUP BY source")->fetchAll();
$lastRuns = $pdo->query("SELECT * FROM parser_runs ORDER BY id DESC LIMIT 8")->fetchAll();

ob_start();
?>
<div class="cards">
  <div class="kcard"><div class="kcard__num"><?= $stats['products'] ?></div><div class="kcard__lbl">Mahsulot</div></div>
  <div class="kcard"><div class="kcard__num"><?= $stats['sources'] ?></div><div class="kcard__lbl">Faol manba</div></div>
  <div class="kcard"><div class="kcard__num"><?= $stats['markets'] ?></div><div class="kcard__lbl">Custom market</div></div>
  <div class="kcard"><div class="kcard__num"><?= $stats['runs'] ?></div><div class="kcard__lbl">Parser ishlari</div></div>
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
