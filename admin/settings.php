<?php
declare(strict_types=1);

use MarketBot\Core\Auth;
use MarketBot\Core\Csrf;
use MarketBot\Core\SettingsRepository;

$config = require __DIR__ . '/_bootstrap.php';
Auth::requireLogin();

$repo = new SettingsRepository();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'CSRF token noto\'g\'ri'];
        header('Location: settings.php'); exit;
    }

    $section = (string) ($_POST['section'] ?? '');

    if ($section === 'fx') {
        // Validate each rate as a positive number; empty string clears the override.
        $pairs = [];
        foreach (['rub', 'usd', 'eur', 'kzt'] as $cur) {
            $key = 'fx_' . $cur . '_to_uzs';
            $val = trim((string) ($_POST[$key] ?? ''));
            if ($val === '') {
                $pairs[$key] = null;
                continue;
            }
            if (!is_numeric($val) || (float) $val <= 0) {
                $_SESSION['flash'] = [
                    'type' => 'error',
                    'message' => "FX qiymati noto'g'ri: $key = $val",
                ];
                header('Location: settings.php'); exit;
            }
            $pairs[$key] = (string) (float) $val;
        }
        $repo->setMany($pairs);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Valyuta kurslari saqlandi'];
    } elseif ($section === 'site') {
        $repo->setMany([
            'site_name'        => trim((string) ($_POST['site_name'] ?? '')),
            'support_email'    => trim((string) ($_POST['support_email'] ?? '')),
            'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0',
        ]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Sayt sozlamalari saqlandi'];
    }

    header('Location: settings.php'); exit;
}

$all = $repo->all();
$fxKeys = ['rub' => 'RUB', 'usd' => 'USD', 'eur' => 'EUR', 'kzt' => 'KZT'];

ob_start();
?>
<div class="grid-2col">
  <section class="card">
    <h2>💱 Valyuta kurslari (UZS ga)</h2>
    <p class="muted">Bo'sh qoldirsangiz, <code>.env</code> dagi <code>FX_*_TO_UZS</code> qiymati ishlatiladi.</p>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="section" value="fx">
      <?php foreach ($fxKeys as $slug => $label):
          $k = 'fx_' . $slug . '_to_uzs';
          $v = htmlspecialchars((string) ($all[$k] ?? ''));
      ?>
        <label class="field">
          <span><?= $label ?> &rarr; UZS</span>
          <input type="number" step="0.0001" min="0" name="<?= $k ?>" value="<?= $v ?>" placeholder="masalan, 160">
        </label>
      <?php endforeach; ?>
      <button type="submit" class="btn-primary">Kurslarni saqlash</button>
    </form>
  </section>

  <section class="card">
    <h2>🏷️ Sayt sozlamalari</h2>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="section" value="site">
      <label class="field">
        <span>Sayt nomi</span>
        <input type="text" name="site_name" maxlength="64" value="<?= htmlspecialchars((string) ($all['site_name'] ?? 'MarketCompare')) ?>">
      </label>
      <label class="field">
        <span>Qo'llab-quvvatlash email</span>
        <input type="email" name="support_email" maxlength="160" value="<?= htmlspecialchars((string) ($all['support_email'] ?? '')) ?>">
      </label>
      <label class="field-check">
        <input type="checkbox" name="maintenance_mode" <?= ($all['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
        <span>Texnik ish rejimi (sayt vaqtincha to'xtatiladi)</span>
      </label>
      <button type="submit" class="btn-primary">Saqlash</button>
    </form>
  </section>
</div>
<?php
$content = ob_get_clean();
$pageTitle  = 'Sozlamalar';
$activeMenu = 'settings';
require __DIR__ . '/_layout.php';
