<?php
declare(strict_types=1);

use MarketBot\Core\Auth;
use MarketBot\Core\Csrf;
use MarketBot\WebApp\HotKeywordRepository;
use MarketBot\WebApp\SearchAnalytics;

$config = require __DIR__ . '/_bootstrap.php';
Auth::requireLogin();

$repo = new HotKeywordRepository();
$analytics = new SearchAnalytics();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (Csrf::check($_POST['csrf_token'] ?? null)) {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add') {
            $kw = trim((string) ($_POST['keyword'] ?? ''));
            $pr = (int) ($_POST['priority'] ?? 10);
            if ($kw !== '') {
                $added = $repo->add($kw, $pr);
                $_SESSION['flash'] = $added
                    ? ['type' => 'success', 'message' => 'Qo\'shildi: ' . $kw]
                    : ['type' => 'error',   'message' => 'Bu so\'z avvaldan ro\'yxatda yoki bo\'sh'];
            }
        } elseif ($action === 'bulk-add') {
            // Promote selected zero-result queries to the hot list.
            $kws = $_POST['kw'] ?? [];
            $n = 0;
            if (is_array($kws)) {
                foreach ($kws as $kw) {
                    if ($repo->add((string) $kw, 20)) $n++;
                }
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => "$n ta yangi so'z qo'shildi"];
        } elseif ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $a  = (int) ($_POST['active'] ?? 0);
            $repo->setActive($id, (bool) $a);
        } elseif ($action === 'priority') {
            $repo->setPriority((int) ($_POST['id'] ?? 0), (int) ($_POST['priority'] ?? 10));
        } elseif ($action === 'delete') {
            $repo->delete((int) ($_POST['id'] ?? 0));
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'O\'chirildi'];
        }
    }
    header('Location: keywords.php');
    exit;
}

$keywords      = $repo->all(false);
$topQueries    = $analytics->topQueries(24, 30);
$zeroQueries   = $analytics->zeroResultQueries(24, 30);
$h = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="panel">
  <h2>Hot Keywords — kron oldindan to'plovchi so'zlar</h2>
  <p class="muted">
    Bu so'zlar bo'yicha har 10 daqiqada cron Uzum/WB/boshqa marketlardan
    mahsulot yig'adi va bazaga yozadi. Foydalanuvchi qidirganda darhol
    natija ko'rinadi (cache'dan, 50-80 ms).
  </p>

  <form method="post" class="form-row" style="margin-bottom:1rem">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="add">
    <input type="text" name="keyword" placeholder="masalan: samsung galaxy" required style="flex:2">
    <input type="number" name="priority" value="10" min="1" max="100" style="width:90px" title="Prioritet (1-100, yuqori = avval olinadi)">
    <button type="submit" class="btn btn-primary">Qo'shish</button>
  </form>

  <table class="data-table">
    <thead>
      <tr>
        <th>So'z</th>
        <th>Prioritet</th>
        <th>Faol</th>
        <th>So'nggi yig'im</th>
        <th>Natija</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$keywords): ?>
        <tr><td colspan="6" class="muted">Hali so'z yo'q. Yuqoridagi formadan qo'shing yoki pastdagi zero-result so'zlarni promote qiling.</td></tr>
      <?php endif; ?>
      <?php foreach ($keywords as $kw): ?>
        <tr>
          <td><strong><?= $h($kw['keyword']) ?></strong></td>
          <td>
            <form method="post" class="inline-form">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="priority">
              <input type="hidden" name="id" value="<?= (int) $kw['id'] ?>">
              <input type="number" name="priority" value="<?= (int) $kw['priority'] ?>" min="1" max="100" style="width:60px" onchange="this.form.submit()">
            </form>
          </td>
          <td>
            <form method="post" class="inline-form">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $kw['id'] ?>">
              <input type="hidden" name="active" value="<?= ((int) $kw['is_active']) ? 0 : 1 ?>">
              <button type="submit" class="btn btn-small <?= ((int) $kw['is_active']) ? '' : 'btn-secondary' ?>">
                <?= ((int) $kw['is_active']) ? 'Yoq.' : 'O\'chiq' ?>
              </button>
            </form>
          </td>
          <td><?= $h($kw['last_fetched_at']) ?: '—' ?></td>
          <td><?= (int) $kw['last_results'] ?></td>
          <td>
            <form method="post" class="inline-form" onsubmit="return confirm('O\'chirilsinmi?')">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $kw['id'] ?>">
              <button type="submit" class="btn btn-small btn-danger">×</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($zeroQueries): ?>
<div class="panel" style="margin-top:1.5rem">
  <h3>0 natija bergan qidiruvlar (24 soat)</h3>
  <p class="muted">Foydalanuvchilar qidiruvchi ammo bazada yo'q. Bularni hot keywords ro'yxatiga qo'shsangiz, keyingi cron bularni avtomatik to'playdi.</p>
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="bulk-add">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:40px"><input type="checkbox" onclick="document.querySelectorAll('[name=\'kw[]\']').forEach(c=>c.checked=this.checked)"></th>
          <th>So'z</th>
          <th>Necha marta qidirilgan</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($zeroQueries as $row): ?>
          <tr>
            <td><input type="checkbox" name="kw[]" value="<?= $h($row['query']) ?>"></td>
            <td><?= $h($row['query']) ?></td>
            <td><?= (int) $row['hits'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <button type="submit" class="btn btn-primary" style="margin-top:1rem">Tanlanganlarni hot keywords'ga qo'shish</button>
  </form>
</div>
<?php endif; ?>

<?php if ($topQueries): ?>
<div class="panel" style="margin-top:1.5rem">
  <h3>Top qidiruvlar (24 soat)</h3>
  <table class="data-table">
    <thead>
      <tr>
        <th>So'z</th>
        <th>Qidiruvlar</th>
        <th>O'rtacha natija</th>
        <th>0 natija</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($topQueries as $row): ?>
        <tr>
          <td><?= $h($row['query']) ?></td>
          <td><?= (int) $row['hits'] ?></td>
          <td><?= (int) $row['last_results'] ?></td>
          <td><?= (int) $row['zero_count'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:1.5rem">
  <h3>Cron sozlash</h3>
  <p class="muted">Hot keywords avtomatik to'plash uchun crontab'ga qo'shing:</p>
  <pre style="background:#0f172a;color:#e2e8f0;padding:1rem;border-radius:8px;overflow-x:auto"><code>*/10 * * * * /usr/bin/php /www/narxbor.uz/bin/refresh-hot-keywords.php &gt;&gt; /var/log/narxbor-cron.log 2&gt;&amp;1</code></pre>
</div>

<?php
$content = ob_get_clean();
$pageTitle  = 'Hot Keywords';
$activeMenu = 'keywords';
require __DIR__ . '/_layout.php';
