<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\Router;

AppConfig::boot();

$router = new Router();
require __DIR__ . '/routes/web.php';

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');

