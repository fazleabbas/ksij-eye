<?php
declare(strict_types=1);

namespace App\Core;

/** Admin-editable system settings (settings table), cached per request. */
final class Settings
{
    private static ?array $values = null;

    public static function all(): array
    {
        if (self::$values === null) {
            self::$values = [];
            foreach (Database::all('SELECT setting_key, setting_value FROM settings') as $row) {
                self::$values[$row['setting_key']] = $row['setting_value'];
            }
        }
        return self::$values;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::all()[$key] ?? null;
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function flush(): void
    {
        self::$values = null;
    }
}
