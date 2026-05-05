<?php
declare(strict_types=1);

use MarketBot\Admin\BroadcastService;
use MarketBot\Core\Auth;
use MarketBot\Core\Csrf;
use MarketBot\Core\Database;
use MarketBot\Telegram\TelegramAPI;

$config = require __DIR__ . '/_bootstrap.php';
Auth::requireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'CSRF xato'];
        header('Location: broadcast.php');
        exit;
    }
    $type = (string) ($_POST['type'] ?? 'text');
    $text = (string) ($_POST['text'] ?? '');
    $parseMode = (string) ($_POST['parse_mode'] ?? '');
    $send = isset($_POST['send_now']);

    $service = new BroadcastService(new TelegramAPI((string) $config['telegram']['token']), (string) $config['paths']['uploads_broadcast']);

    $mediaPath = null;
    if (in_array($type, ['photo', 'video'], true) && !empty($_FILES['media']['tmp_name'])) {
        $mediaPath = $service->storeUpload($_FILES['media']);
        if ($mediaPath === null) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Faylni yuklab bo\'lmadi'];
            header('Location: broadcast.php');
            exit;
        }
    }

    if ($type === 'text' && trim($text) === '') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Matn bo\'sh bo\'lmasin'];
        header('Location: broadcast.php');
        exit;
    }

    $admin = Auth::user();
    $id = $service->create([
        'admin_id'   => (int) ($admin['id'] ?? 0),
        'type'       => $type,
        'text'       => $text,
        'media_path' => $mediaPath,
        'parse_mode' => $parseMode,
    ]);

    if ($send) {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $res = $service->run($id);
        $_SESSION['flash'] = ['type' => 'success',
            'message' => sprintf("Yuborildi: ✓ %d, ✗ %d (jami %d)", $res['sent'], $res['failed'], $res['total'])];
    } else {
        $_SESSION['flash'] = ['type' => 'success', 'message' => "Saqlandi (#$id) — keyin yuborish uchun: php bin/send-broadcast.php --id=$id"];
    }
    header('Location: broadcast.php');
    exit;
}

$rows = Database::pdo()->query("SELECT * FROM broadcasts ORDER BY id DESC LIMIT 30")->fetchAll();

ob_start();
?>
<div class="grid-2">
  <form class="panel form" method="post" enctype="multipart/form-data">
    <h2>Yangi xabar</h2>
    <?= Csrf::field() ?>
    <label>Tur
      <select name="type" id="type">
        <option value="text">Matn</option>
        <option value="photo">Rasm + matn (caption)</option>
        <option value="video">Video + matn (caption)</option>
      </select>
    </label>
    <label>Matn / Caption
      <textarea name="text" rows="6" placeholder="Salom! Bizda yangi mahsulotlar..."></textarea>
    </label>
    <label>Format
      <select name="parse_mode">
        <option value="">— oddiy matn —</option>
        <option value="HTML">HTML</option>
        <option value="Markdown">Markdown</option>
      </select>
    </label>
    <label id="media-row">Rasm/Video fayli
      <input type="file" name="media" accept="image/*,video/*">
    </label>
    <div class="row">
      <button class="btn" type="submit">Saqlash</button>
      <button class="btn btn--primary" type="submit" name="send_now" value="1" onclick="return confirm('Hozir yuborilsinmi? Bu jarayon biroz vaqt oladi.')">Hozir yuborish</button>
    </div>
    <p class="muted">Katta auditoriyaga yuborishda CLI'dan foydalaning: <code>php bin/send-broadcast.php --id=&lt;ID&gt;</code></p>
  </form>

  <div class="panel">
    <h2>Statistika</h2>
    <p class="muted">Tarixdan ko'rishingiz mumkin: nechta foydalanuvchiga muvaffaqiyatli, nechtasiga xato bilan yuborilgan.</p>
  </div>
</div>

<h2>Tarix</h2>
<table class="table">
  <thead><tr><th>ID</th><th>Tur</th><th>Matn</th><th>Status</th><th>✓</th><th>✗</th><th>Jami</th><th>Vaqt</th></tr></thead>
  <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="8"><em>Hech qanday xabar yuborilmagan</em></td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td>#<?= (int) $r['id'] ?></td>
        <td><?= htmlspecialchars((string) $r['type']) ?></td>
        <td class="ellipsis" style="max-width:280px"><?= htmlspecialchars(mb_substr((string) ($r['text'] ?? ''), 0, 100)) ?></td>
        <td><span class="status status--<?= htmlspecialchars((string) $r['status']) ?>"><?= htmlspecialchars((string) $r['status']) ?></span></td>
        <td><?= (int) $r['sent_count'] ?></td>
        <td><?= (int) $r['failed_count'] ?></td>
        <td><?= (int) $r['total_count'] ?></td>
        <td class="muted"><?= htmlspecialchars((string) $r['created_at']) ?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<script>
  document.getElementById('type').addEventListener('change', e => {
    const v = e.target.value;
    document.getElementById('media-row').style.display = (v === 'text') ? 'none' : '';
  });
  if (document.getElementById('type').value === 'text') {
    document.getElementById('media-row').style.display = 'none';
  }
</script>

<?php
$content = ob_get_clean();
$pageTitle = 'Xabar yuborish';
$activeMenu = 'broadcast';
include __DIR__ . '/_layout.php';
