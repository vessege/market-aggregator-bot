<?php
declare(strict_types=1);

use MarketBot\Core\Auth;

require __DIR__ . '/_bootstrap.php';
Auth::logout();
header('Location: login.php');
exit;
