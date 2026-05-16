<?php
declare(strict_types=1);

use MarketBot\Core\Auth;
use MarketBot\Core\Csrf;
use MarketBot\Core\Database;
use MarketBot\Parsers\AliexpressParser;
use MarketBot\Parsers\HttpClient;
use MarketBot\Parsers\OlxParser;
use MarketBot\Parsers\OzonParser;
use MarketBot\Parsers\ParserManager;
use MarketBot\Parsers\UzumParser;
use MarketBot\Parsers\WildberriesParser;
use MarketBot\Parsers\YandexMarketParser;

$config = require __DIR__ . '/_bootstrap.php';
Auth::requireLogin();

$http = new HttpClient(
    userAgent: $config['parser']['user_agent'],
    timeout:   $config['parser']['timeout'],
    delayMs:   $config['parser']['delay_ms']
);
$manager = new ParserManager($http);
$manager->register(new UzumParser($http));
$manager->register(new WildberriesParser($http));
$manager->register(new OlxParser($http));
$manager->register(new OzonParser($http));
$manager->register(new AliexpressParser($http));
$manager->register(new YandexMarketParser($http));

$result = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'CSRF xato'];
    } else {
        $source = (string) ($_POST['source'] ?? '');
        $limit  = max(1, (int) ($_POST['limit']  ?? 30));
        $depth  = max(1, (int) ($_POST['depth']  ?? 2));
        $query  = trim((string) ($_POST['query'] ?? ''));
        // Best-effort; on hardened hosts set_time_limit is restricted and just
        // returns false. We don't suppress the error so it shows up in logs.
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }
        try {
            $opts = ['max_items' => $limit, 'max_depth' => $depth];
            if ($query !== '') $opts['query'] = $query;
            $result = $manager->runSource($source, $opts);
            $_SESSION['flash'] = ['type' => 'success', 'message' =>
                sprintf("Parser bajarildi: +%d yangi, %d yangilangan", $result['inserted'], $result['updated'])];
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Xato: ' . $e->getMessage()];
        }
    }
}

$runs = Database::pdo()->query("SELECT * FROM parser_runs ORDER BY id DESC LIMIT 30")->fetchAll();

ob_start();
?>
<div class="grid-2">
  <div class="panel">
    <h2>Parserni ishga tushirish</h2>
    <p class="muted">
      Tanlangan manbadan mahsulotlarni yuklab, ma'lumotlar bazasiga yozadi.
      "Limit" — maksimal yangi mahsulotlar soni. Real ishlab chiqarishda bu cron orqali bajarilishi kerak.
    </p>
    <form method="post" class="form">
      <?= Csrf::field() ?>
      <label>Manba
        <select name="source" required>
          <?php foreach ($manager->all() as $src => $p): ?>
            <option value="<?= htmlspecialchars($src) ?>"><?= htmlspecialchars($p->displayName()) ?> <?= in_array($src, ['uzum','wildberries'], true) ? '(ishlaydi)' : '(stub)' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="row">
        <label>Limit <input type="number" name="limit" value="30" min="1" max="500"></label>
        <label>Depth <input type="number" name="depth" value="2" min="1" max="5"></label>
      </div>
      <label>Qidiruv (Wildberries uchun) <input type="text" name="query" placeholder="masalan: smartfon"></label>
      <button class="btn btn--primary" type="submit">Ishga tushirish</button>
    </form>
  </div>

  <div class="panel">
    <h2>Cron sozlash</h2>
    <p class="muted">Mahsulotlarni jonli holatda saqlash uchun har 15 daqiqada parser ishga tushirilsin:</p>
    <pre class="code">
*/15 * * * * cd /var/www/marketbot &amp;&amp; php bin/run-parser.php --source=uzum --limit=100
0 * * * *    cd /var/www/marketbot &amp;&amp; php bin/run-parser.php --source=wildberries --limit=50 --query=smartfon
    </pre>
    <p class="muted">Foydalanuvchi mahsulot kartasini ochganda esa narx 5 daqiqadan eski bo'lsa avtomatik tarzda saytdan qayta tekshiriladi.</p>
  </div>
</div>

<h2>Parser ishlari tarixi</h2>
<table class="table">
  <thead><tr><th>ID</th><th>Manba</th><th>Status</th><th>Yangi</th><th>Yangilangan</th><th>Boshlangan</th><th>Tugagan</th><th>Xato</th></tr></thead>
  <tbody>
    <?php if (!$runs): ?>
      <tr><td colspan="8"><em>Hali ishga tushirilmagan</em></td></tr>
    <?php else: foreach ($runs as $r): ?>
      <tr>
        <td>#<?= (int) $r['id'] ?></td>
        <td><?= htmlspecialchars((string) $r['source']) ?></td>
        <td><span class="status status--<?= htmlspecialchars((string) $r['status']) ?>"><?= htmlspecialchars((string) $r['status']) ?></span></td>
        <td><?= (int) $r['inserted_count'] ?></td>
        <td><?= (int) $r['updated_count'] ?></td>
        <td><?= htmlspecialchars((string) $r['started_at']) ?></td>
        <td><?= htmlspecialchars((string) ($r['finished_at'] ?? '')) ?></td>
        <td class="muted"><?= htmlspecialchars(substr((string) ($r['error'] ?? ''), 0, 80)) ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>
<?php
$content = ob_get_clean();
$pageTitle = 'Parserlar';
$activeMenu = 'parsers';
include __DIR__ . '/_layout.php';
