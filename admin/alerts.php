<?php
declare(strict_types=1);

use MarketBot\Core\Auth;
use MarketBot\Core\Csrf;
use MarketBot\WebApp\AlertRepository;

$config = require __DIR__ . '/_bootstrap.php';
Auth::requireLogin();

$repo = new AlertRepository();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'CSRF noto\'g\'ri'];
        header('Location: alerts.php'); exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'cancel') {
        $repo->cancel((int) ($_POST['id'] ?? 0));
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Bekor qilindi'];
    }
    header('Location: alerts.php'); exit;
}

$alerts = $repo->listAll(200);

ob_start();
?>
<div class="card">
  <h2>🔔 Narx ogohlantirishlari</h2>
  <p class="muted">
    Foydalanuvchilar mahsulot sahifasidan "narx tushganda email yuborish"
    so'rovini qo'shadi. Cron orqali jonatish: <code>php bin/check-alerts.php</code>.
  </p>
  <table class="data-table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Mahsulot</th>
        <th>Email</th>
        <th>Maqsad</th>
        <th>Holat</th>
        <th>Yaratilgan</th>
        <th>Yuborilgan</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$alerts): ?>
      <tr><td colspan="8" class="muted">Hozircha ogohlantirish yo'q.</td></tr>
    <?php else: foreach ($alerts as $a): ?>
      <tr>
        <td><?= (int) $a['id'] ?></td>
        <td><?= htmlspecialchars((string) ($a['product_title'] ?? '—')) ?></td>
        <td><?= htmlspecialchars((string) $a['email']) ?></td>
        <td><?= number_format((float) $a['target_price']) ?> <?= htmlspecialchars((string) $a['currency']) ?></td>
        <td><span class="status status--<?= htmlspecialchars((string) $a['status']) ?>"><?= htmlspecialchars((string) $a['status']) ?></span></td>
        <td><?= htmlspecialchars((string) $a['created_at']) ?></td>
        <td><?= htmlspecialchars((string) ($a['notified_at'] ?? '—')) ?></td>
        <td>
          <?php if ($a['status'] === 'active'): ?>
          <form method="post" onsubmit="return confirm('Bekor qililsinmi?');" style="display:inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
            <button type="submit" class="link-danger">Bekor qilish</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
$pageTitle  = 'Ogohlantirishlar';
$activeMenu = 'alerts';
require __DIR__ . '/_layout.php';
