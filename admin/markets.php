<?php
declare(strict_types=1);

use MarketBot\Admin\DynamicSourceRepository;
use MarketBot\Core\Auth;
use MarketBot\Core\Csrf;

$config = require __DIR__ . '/_bootstrap.php';
Auth::requireLogin();

$repo = new DynamicSourceRepository();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (Csrf::check($_POST['csrf_token'] ?? null)) {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $data = [
                'slug'             => trim((string) ($_POST['slug'] ?? '')),
                'display_name'     => trim((string) ($_POST['display_name'] ?? '')),
                'base_url'         => trim((string) ($_POST['base_url'] ?? '')),
                'search_url'       => trim((string) ($_POST['search_url'] ?? '')),
                'http_method'      => (string) ($_POST['http_method'] ?? 'GET'),
                'headers_json'     => trim((string) ($_POST['headers_json'] ?? '')),
                'body_template'    => trim((string) ($_POST['body_template'] ?? '')),
                'items_path'       => trim((string) ($_POST['items_path'] ?? '')),
                'field_id'         => trim((string) ($_POST['field_id'] ?? '')),
                'field_title'      => trim((string) ($_POST['field_title'] ?? '')),
                'field_price'      => trim((string) ($_POST['field_price'] ?? '')),
                'field_old_price'  => trim((string) ($_POST['field_old_price'] ?? '')),
                'field_currency'   => trim((string) ($_POST['field_currency'] ?? '')),
                'field_image'      => trim((string) ($_POST['field_image'] ?? '')),
                'field_url'        => trim((string) ($_POST['field_url'] ?? '')),
                'field_rating'     => trim((string) ($_POST['field_rating'] ?? '')),
                'field_reviews'    => trim((string) ($_POST['field_reviews'] ?? '')),
                'field_sold'       => trim((string) ($_POST['field_sold'] ?? '')),
                'external_url_tpl' => trim((string) ($_POST['external_url_tpl'] ?? '')),
                'is_active'        => isset($_POST['is_active']) ? 1 : 0,
            ];
            try {
                if ($data['headers_json'] !== '') {
                    $decoded = json_decode($data['headers_json'], true);
                    if (!is_array($decoded)) {
                        throw new \RuntimeException('Headers JSON noto\'g\'ri formatda');
                    }
                }
                if ($id > 0) {
                    $repo->update($id, $data);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Yangilandi'];
                } else {
                    $repo->create($data);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Qo\'shildi'];
                }
            } catch (\Throwable $e) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Xato: ' . $e->getMessage()];
            }
        } elseif ($action === 'toggle') {
            $repo->toggleActive((int) ($_POST['id'] ?? 0));
        } elseif ($action === 'delete') {
            $repo->delete((int) ($_POST['id'] ?? 0));
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'O\'chirildi'];
        }
    }
    header('Location: markets.php');
    exit;
}

$editing = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = $repo->findById($editId);
}

$rows = $repo->all();

$h = static fn(?string $s): string => htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="panel">
  <h2><?= $editing ? 'Market tahrirlash' : 'Yangi market qo\'shish' ?></h2>
  <p class="muted">
    Admin panelda yangi marketplace qo'shing — uning JSON API'sini bering, bot
    qidiruv so'rovini darhol shu market'ga ham yuboradi va natijalarni mahalliy
    bazaga saqlaydi. <code>{query}</code> va <code>{limit}</code> URL/header/body
    ichida o'rniga qo'yiladi (URL'da avtomatik <code>urlencode</code>'lanadi;
    saqlangan holda ishlatish uchun <code>{!query}</code> yozing).
  </p>

  <form method="post" class="form">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

    <div class="row">
      <label>Slug (lotin, masalan: <code>sello</code>)
        <input type="text" name="slug" required pattern="[a-z0-9_]+" value="<?= $h($editing['slug'] ?? '') ?>">
      </label>
      <label>Display name
        <input type="text" name="display_name" required value="<?= $h($editing['display_name'] ?? '') ?>">
      </label>
    </div>

    <div class="row">
      <label>Base URL (ixtiyoriy)
        <input type="url" name="base_url" placeholder="https://api.sello.uz" value="<?= $h($editing['base_url'] ?? '') ?>">
      </label>
      <label>HTTP method
        <select name="http_method">
          <option value="GET"  <?= ($editing['http_method'] ?? 'GET') === 'GET'  ? 'selected' : '' ?>>GET</option>
          <option value="POST" <?= ($editing['http_method'] ?? '') === 'POST' ? 'selected' : '' ?>>POST</option>
        </select>
      </label>
    </div>

    <label>Search URL <span class="muted">(<code>{query}</code>, <code>{limit}</code> ishlatish mumkin)</span>
      <input type="text" name="search_url" required
             placeholder="https://api.sello.uz/v1/search?text={query}&limit={limit}"
             value="<?= $h($editing['search_url'] ?? '') ?>">
    </label>

    <label>Headers (JSON formatida, ixtiyoriy)
      <textarea name="headers_json" rows="3" placeholder='{"Authorization":"Bearer xxx","Accept-Language":"uz-UZ"}'><?= $h($editing['headers_json'] ?? '') ?></textarea>
    </label>

    <label>Body template (POST uchun, ixtiyoriy)
      <textarea name="body_template" rows="4" placeholder='{"query":"{!query}","limit":{limit}}'><?= $h($editing['body_template'] ?? '') ?></textarea>
    </label>

    <h3 style="margin-top:1.5rem">JSON yo'llari (response field mapping)</h3>
    <p class="muted">
      Dot-path bilan: <code>data.products</code>, <code>payload.results.items</code> va h.k.
      Maydonlar mahsulotlar massivi ichidagi har bir element bo'yicha o'qiladi.
    </p>

    <div class="row">
      <label>Items path (qaysi yo'lda mahsulotlar massivi)
        <input type="text" name="items_path" placeholder="data.products" value="<?= $h($editing['items_path'] ?? '') ?>">
      </label>
      <label>External URL template <span class="muted">(<code>{id}</code>)</span>
        <input type="text" name="external_url_tpl" placeholder="https://sello.uz/p/{id}" value="<?= $h($editing['external_url_tpl'] ?? '') ?>">
      </label>
    </div>

    <div class="row">
      <label>field_id <input type="text" name="field_id" placeholder="id" value="<?= $h($editing['field_id'] ?? '') ?>"></label>
      <label>field_title <input type="text" name="field_title" placeholder="name" value="<?= $h($editing['field_title'] ?? '') ?>"></label>
    </div>
    <div class="row">
      <label>field_price <input type="text" name="field_price" placeholder="price.value" value="<?= $h($editing['field_price'] ?? '') ?>"></label>
      <label>field_old_price <input type="text" name="field_old_price" placeholder="price.old" value="<?= $h($editing['field_old_price'] ?? '') ?>"></label>
    </div>
    <div class="row">
      <label>field_currency <input type="text" name="field_currency" placeholder="price.currency" value="<?= $h($editing['field_currency'] ?? '') ?>"></label>
      <label>field_image <input type="text" name="field_image" placeholder="image.url" value="<?= $h($editing['field_image'] ?? '') ?>"></label>
    </div>
    <div class="row">
      <label>field_url <input type="text" name="field_url" placeholder="url" value="<?= $h($editing['field_url'] ?? '') ?>"></label>
      <label>field_rating <input type="text" name="field_rating" placeholder="rating" value="<?= $h($editing['field_rating'] ?? '') ?>"></label>
    </div>
    <div class="row">
      <label>field_reviews <input type="text" name="field_reviews" placeholder="reviews_count" value="<?= $h($editing['field_reviews'] ?? '') ?>"></label>
      <label>field_sold <input type="text" name="field_sold" placeholder="sold_count" value="<?= $h($editing['field_sold'] ?? '') ?>"></label>
    </div>

    <label class="checkbox">
      <input type="checkbox" name="is_active" <?= !$editing || (int) ($editing['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
      Faol (live search ishga tushganda chaqiriladi)
    </label>

    <div class="form__actions">
      <button class="btn btn--primary"><?= $editing ? 'Yangilash' : 'Qo\'shish' ?></button>
      <?php if ($editing): ?>
        <a class="btn" href="markets.php">Bekor qilish</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<h2 style="margin-top:2rem">Qo'shilgan marketlar (<?= count($rows) ?>)</h2>
<table class="table">
  <thead>
    <tr>
      <th>Slug</th><th>Nom</th><th>Method</th><th>URL</th><th>Faol</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($rows)): ?>
    <tr><td colspan="6" class="muted">Hali market qo'shilmagan.</td></tr>
  <?php else: foreach ($rows as $r): ?>
    <tr>
      <td><code><?= $h((string) $r['slug']) ?></code></td>
      <td><?= $h((string) $r['display_name']) ?></td>
      <td><?= $h((string) $r['http_method']) ?></td>
      <td><span class="muted" style="font-size:.85em"><?= $h(mb_strimwidth((string) $r['search_url'], 0, 80, '…')) ?></span></td>
      <td><?= ((int) $r['is_active']) === 1 ? '✅' : '⏸️' ?></td>
      <td>
        <a class="btn btn--sm" href="markets.php?edit=<?= (int) $r['id'] ?>">Tahrirlash</a>
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
$pageTitle = 'Marketlar';
$activeMenu = 'markets';
include __DIR__ . '/_layout.php';
