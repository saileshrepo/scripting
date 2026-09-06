<?php
/**
 * Minimal PSR-4 style autoloader for the Zomunk\ namespace, plus config.
 * Kept dependency-free on purpose: this drops onto any PHP 8 host as-is.
 */

require_once dirname(__DIR__) . '/config/config.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Zomunk\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen('Zomunk\\')));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
