<?php
declare(strict_types=1);

use MarketBot\Core\Auth;
use MarketBot\Core\Csrf;
use MarketBot\Core\Database;

$config = require __DIR__ . '/_bootstrap.php';
Auth::requireLogin();

$pdo = Database::pdo();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && Csrf::check($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        if ($action === 'block') {
            $pdo->prepare('UPDATE users SET is_blocked = 1 - is_blocked WHERE id = :id')->execute(['id' => $id]);
        }
    }
    header('Location: users.php');
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$params = [];
$where = '1=1';
if ($q !== '') {
    $where = '(username LIKE :q OR first_name LIKE :q OR CAST(tg_id AS CHAR) LIKE :q)';
    if (Database::isSqlite()) {
        $where = '(username LIKE :q OR first_name LIKE :q OR CAST(tg_id AS TEXT) LIKE :q)';
    }
    $params['q'] = "%$q%";
}

$total = (int) ($pdo->prepare("SELECT COUNT(*) AS c FROM users WHERE $where") ?? null)->execute($params);
$totalStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM users WHERE $where");
$totalStmt->execute($params);
$total = (int) ($totalStmt->fetch()['c'] ?? 0);

$rowsStmt = $pdo->prepare("SELECT * FROM users WHERE $where ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

ob_start();
?>
<form method="get" class="filters">
  <input type="text" name="q" placeholder="Ism, username, ID..." value="<?= htmlspecialchars($q) ?>">
  <button class="btn">Filtr</button>
</form>

<table class="table">
  <thead><tr><th>ID</th><th>TG ID</th><th>Username</th><th>Ism</th><th>Til</th><th>Bloklangan</th><th>Oxirgi faollik</th><th></th></tr></thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="8"><em>Foydalanuvchi yo'q</em></td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td>#<?= (int) $r['id'] ?></td>
        <td><?= (int) $r['tg_id'] ?></td>
        <td>@<?= htmlspecialchars((string) ($r['username'] ?? '')) ?></td>
        <td><?= htmlspecialchars(trim(((string) ($r['first_name'] ?? '')) . ' ' . ((string) ($r['last_name'] ?? '')))) ?></td>
        <td><?= htmlspecialchars((string) ($r['language_code'] ?? '')) ?></td>
        <td><?= ((int) $r['is_blocked']) ? '🚫' : '✅' ?></td>
        <td class="muted"><?= htmlspecialchars((string) ($r['last_seen_at'] ?? '')) ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="block">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn btn--sm">Toggle block</button>
          </form>
        </td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<div class="pagination">
  <?php
  $pages = max(1, (int) ceil($total / $perPage));
  for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++):
  ?>
    <a class="<?= $i === $page ? 'is-active' : '' ?>" href="?page=<?= $i ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"><?= $i ?></a>
  <?php endfor; ?>
  <span class="muted">Jami: <?= $total ?></span>
</div>

<?php
$content = ob_get_clean();
$pageTitle = 'Foydalanuvchilar';
$activeMenu = 'users';
include __DIR__ . '/_layout.php';
