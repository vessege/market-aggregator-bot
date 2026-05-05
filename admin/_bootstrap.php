<?php
declare(strict_types=1);

use MarketBot\Core\Auth;
use MarketBot\Core\Bootstrap;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';
$config = Bootstrap::init();

Auth::start((string) $config['app']['session']);

return $config;
