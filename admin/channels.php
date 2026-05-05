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
        if ($action === 'add') {
            $chatId = trim((string) ($_POST['chat_id'] ?? ''));
            $title = trim((string) ($_POST['title'] ?? ''));
            $invite = trim((string) ($_POST['invite_link'] ?? ''));
            if ($chatId !== '') {
                try {
                    $pdo->prepare(
                        'INSERT INTO subscription_channels (chat_id, title, invite_link, is_active) VALUES (:c, :t, :i, 1)'
                    )->execute(['c' => $chatId, 't' => $title ?: null, 'i' => $invite ?: null]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Kanal qo\'shildi'];
                } catch (\Throwable $e) {
                    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Xato (mavjud bo\'lishi mumkin): ' . $e->getMessage()];
                }
            }
        } elseif ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('UPDATE subscription_channels SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $id]);
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('DELETE FROM subscription_channels WHERE id = :id')->execute(['id' => $id]);
            }
        }
    }
    header('Location: channels.php');
    exit;
}

$rows = $pdo->query('SELECT * FROM subscription_channels ORDER BY position, id')->fetchAll();

ob_start();
?>
<div class="panel">
  <h2>Yangi kanal qo'shish</h2>
  <p class="muted">
    Bot tegishli kanal(lar)ga obuna bo'lmagan foydalanuvchilarga botdan foydalanishga ruxsat bermaydi.
    <strong>Muhim</strong>: bot kanalda admin bo'lishi shart (kanalga qo'shing va admin qiling), aks holda <code>getChatMember</code> ishlamaydi.
    Cheksiz miqdordagi kanallar qo'shishingiz mumkin.
  </p>
  <form method="post" class="form">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="add">
    <div class="row">
      <label>Chat ID yoki @username
        <input type="text" name="chat_id" required placeholder="-1001234567890 yoki @kanalim">
      </label>
      <label>Sarlavha
        <input type="text" name="title" placeholder="Mening kanalim">
      </label>
    </div>
    <label>Invite link (ixtiyoriy)
      <input type="text" name="invite_link" placeholder="https://t.me/+abcd1234">
    </label>
    <button class="btn btn--primary" type="submit">Qo'shish</button>
  </form>
</div>

<table class="table">
  <thead><tr><th>ID</th><th>Chat ID</th><th>Sarlavha</th><th>Invite</th><th>Faol</th><th></th></tr></thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="6"><em>Hech qanday kanal qo'shilmagan</em></td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td>#<?= (int) $r['id'] ?></td>
        <td><code><?= htmlspecialchars((string) $r['chat_id']) ?></code></td>
        <td><?= htmlspecialchars((string) ($r['title'] ?? '')) ?></td>
        <td><?php if (!empty($r['invite_link'])): ?><a href="<?= htmlspecialchars((string) $r['invite_link']) ?>" target="_blank" rel="noopener">Link</a><?php endif; ?></td>
        <td><?= ((int) $r['is_active']) ? '✅' : '⏸' ?></td>
        <td>
          <form method="post" style="display:inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn btn--sm">Toggle</button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('O\'chirilsinmi?')">
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

<?php
$content = ob_get_clean();
$pageTitle = 'Majburiy obuna';
$activeMenu = 'channels';
include __DIR__ . '/_layout.php';
