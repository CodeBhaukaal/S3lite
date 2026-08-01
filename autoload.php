<?php
/**
 * PSR-4 autoloader.
 *
 * Composer is optional here: if a vendor/autoload.php exists it is used,
 * otherwise this file provides an equivalent PSR-4 mapping for App\ => src/.
 */
declare(strict_types=1);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
