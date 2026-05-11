<?php
declare(strict_types=1);

use MarketBot\Core\Auth;
use MarketBot\Core\Csrf;

require __DIR__ . '/_bootstrap.php';

// CSRF-protected logout. The sidebar form submits POST with a token (see
// _layout.php). Reject anything else with 405 — no redirect, so a GET probe
// from another site can never silently log the admin out.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !Csrf::check($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Method Not Allowed');
}

Auth::logout();
header('Location: login.php');
exit;
