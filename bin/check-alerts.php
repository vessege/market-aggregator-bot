<?php
declare(strict_types=1);

/**
 * Cron entry: send notification emails for active price alerts whose target
 * price has been hit. Designed to run every few minutes. Idempotent —
 * notified alerts move to status='notified' and are skipped on subsequent runs.
 *
 * Suggested cron line (every 5 min):
 *   *\/5 * * * * /usr/bin/php /path/to/bin/check-alerts.php >> storage/logs/alerts.log 2>&1
 */

use MarketBot\Core\Bootstrap;
use MarketBot\Core\Env;
use MarketBot\Core\Logger;
use MarketBot\WebApp\AlertRepository;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';
Bootstrap::init();

$repo = new AlertRepository();
$pending = $repo->readyToFire();
if (!$pending) {
    fwrite(STDOUT, "[" . date('c') . "] No alerts to fire.\n");
    exit(0);
}

$from = (string) Env::get('MAIL_FROM', 'no-reply@example.com');
$siteUrl = rtrim((string) Env::get('PUBLIC_URL', ''), '/');

$ok = 0;
$err = 0;

foreach ($pending as $a) {
    $email   = (string) $a['email'];
    $title   = (string) ($a['title'] ?? 'Mahsulot');
    $price   = (float)  ($a['price'] ?? 0);
    $curr    = (string) ($a['product_currency'] ?? 'UZS');
    $target  = (float)  ($a['target_price'] ?? 0);
    $extUrl  = (string) ($a['external_url'] ?? '');
    $siteLink = $siteUrl !== '' ? $siteUrl . '/public/' : '';

    $subject = '[MarketCompare] Narx tushdi: ' . $title;
    $body  = "Salom!\n\n";
    $body .= "Siz kuzatayotgan mahsulot narxi maqsadingizdan tushdi:\n\n";
    $body .= "  $title\n";
    $body .= "  Hozirgi narx: " . number_format($price, 0, '.', ' ') . " $curr\n";
    $body .= "  Sizning maqsadingiz: " . number_format($target, 0, '.', ' ') . "\n\n";
    if ($extUrl !== '') $body .= "Mahsulot havolasi: $extUrl\n";
    if ($siteLink !== '') $body .= "Sayt: $siteLink\n";
    $body .= "\n— MarketCompare\n";

    $headers = "From: $from\r\nContent-Type: text/plain; charset=utf-8\r\n";

    $sent = @mail($email, $subject, $body, $headers);
    if ($sent) {
        $repo->markNotified((int) $a['id']);
        $ok++;
        Logger::info('alerts', 'fired', ['id' => $a['id'], 'email' => $email]);
    } else {
        $err++;
        Logger::error('alerts', 'mail() returned false', ['id' => $a['id'], 'email' => $email]);
    }
}

fwrite(STDOUT, sprintf(
    "[%s] Sent %d, failed %d.\n",
    date('c'), $ok, $err,
));
