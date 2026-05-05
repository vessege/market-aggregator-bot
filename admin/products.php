<?php
declare(strict_types=1);

use MarketBot\Core\Auth;
use MarketBot\Core\Csrf;
use MarketBot\Core\Database;

$config = require __DIR__ . '/_bootstrap.php';
Auth::requireLogin();

$pdo = Database::pdo();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (Csrf::check($_POST['csrf_token'] ?? null)) {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'toggle' && $id > 0) {
            $pdo->prepare('UPDATE products SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Mahsulot holati o\'zgartirildi.'];
        } elseif ($action === 'delete' && $id > 0) {
            $pdo->prepare('DELETE FROM favorites WHERE product_id = :id')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Mahsulot o\'chirildi.'];
        }
    }
    header('Location: products.php' . (!empty($_GET['q']) ? '?q=' . urlencode((string) $_GET['q']) : ''));
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$source = (string) ($_GET['source'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(title LIKE :q OR external_id = :eid)';
    $params['q'] = "%$q%";
    $params['eid'] = $q;
}
if ($source !== '') {
    $where[] = 'source = :s';
    $params['s'] = $source;
}
$whereSql = implode(' AND ', $where);

$totalStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM products WHERE $whereSql");
$totalStmt->execute($params);
$total = (int) ($totalStmt->fetch()['c'] ?? 0);

$rowsStmt = $pdo->prepare(
    "SELECT id, source, external_id, title, price, currency, is_active, updated_at
       FROM products WHERE $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset"
);
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

$sources = $pdo->query("SELECT DISTINCT source FROM products ORDER BY source")->fetchAll();

ob_start();
?>
<p class="muted">
  Bot mahsulotlarni avtomatik ravishda marketlardan oladi. Bu yerda faqat ko'rib chiqish, faollashtirish/o'chirish mumkin.
  Yangi mahsulotlar qo'shish uchun "Parserlar" sahifasidan parserni ishga tushiring.
</p>

<form method="get" class="filters">
  <input type="text" name="q" placeholder="Sarlavha yoki ID..." value="<?= htmlspecialchars($q) ?>">
  <select name="source">
    <option value="">— barcha manbalar —</option>
    <?php foreach ($sources as $s): ?>
      <option value="<?= htmlspecialchars((string) $s['source']) ?>" <?= $source === $s['source'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $s['source']) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn">Filtr</button>
</form>

<table class="table">
  <thead><tr><th>ID</th><th>Manba</th><th>Sarlavha</th><th>Narx</th><th>Faol</th><th>Yangilangan</th><th></th></tr></thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="7"><em>Topilmadi</em></td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td>#<?= (int) $r['id'] ?></td>
        <td><?= htmlspecialchars((string) $r['source']) ?></td>
        <td class="ellipsis" style="max-width:380px"><?= htmlspecialchars((string) $r['title']) ?></td>
        <td><?= number_format((float) $r['price'], 0, ',', ' ') ?> <small><?= htmlspecialchars((string) $r['currency']) ?></small></td>
        <td><?= ((int) $r['is_active']) ? '✅' : '⏸' ?></td>
        <td class="muted"><?= htmlspecialchars((string) $r['updated_at']) ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn btn--sm">Toggle</button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('O\'chirish?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn btn--sm btn--danger">O'chirish</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<div class="pagination">
  <?php
  $pages = max(1, (int) ceil($total / $perPage));
  $base = '?' . http_build_query(array_filter(['q' => $q, 'source' => $source]));
  for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++):
  ?>
    <a class="<?= $i === $page ? 'is-active' : '' ?>" href="<?= htmlspecialchars($base) ?>&page=<?= $i ?>"><?= $i ?></a>
  <?php endfor; ?>
  <span class="muted">Jami: <?= $total ?></span>
</div>

<?php
$content = ob_get_clean();
$pageTitle = 'Mahsulotlar';
$activeMenu = 'products';
include __DIR__ . '/_layout.php';
