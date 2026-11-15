<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Private configuration (DB credentials, encryption keys) loaded from outside the web root.
 * Location: KSI_PRIVATE_DIR env var, or <xampp>/ksij_eye_private.
 */
final class Config
{
    private static array $data = [];

    public static function privateDir(): string
    {
        $dir = getenv('KSI_PRIVATE_DIR') ?: dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'ksij_eye_private';
        return rtrim(str_replace('\\', '/', $dir), '/');
    }

    public static function load(): void
    {
        $file = self::privateDir() . '/config.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Application is not configured (private config missing).');
        }
        self::$data = require $file;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function path(string $name): string
    {
        return rtrim(str_replace('\\', '/', (string) self::get("paths.$name", '')), '/');
    }

    public static function isProduction(): bool
    {
        return self::get('env') === 'production';
    }
}
