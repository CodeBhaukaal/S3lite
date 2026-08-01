<?php
/**
 * Front controller — every web request enters here.
 */
declare(strict_types=1);

define('S3LITE_START', microtime(true));

$basePath = dirname(__DIR__);

require $basePath . '/autoload.php';
require $basePath . '/src/Core/helpers.php';

use App\Core\App;

$app = App::boot($basePath);
$app->run();
