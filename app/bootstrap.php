<?php
/**
 * Application bootstrap: runtime hardening, class autoloading and private configuration.
 * Included by the page entry (index.php), the API front controller and CLI tools.
 */
declare(strict_types=1);

defined('KSI_APP') || define('KSI_APP', true);

// Hardening applied per request so the shared XAMPP php.ini (used by other projects) stays untouched.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (!defined('KSI_SKIP_CONFIG')) {
    App\Core\Config::load();
    ini_set('error_log', App\Core\Config::path('logs') . '/php-error.log');
}
