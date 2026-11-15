<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Format;
use App\Core\Http\HttpException;

/**
 * Validation for nested clinical forms. Values are read by dot path ("refractions.final.sph_right")
 * and errors are keyed by the same path, so the form can highlight the exact input.
 * Blank values read as null; every problem is collected before throwIfInvalid() reports them together.
 */
final class ClinicalInput
{
    private array $errors = [];

    public function __construct(private readonly array $data)
    {
    }

    public function raw(string $path): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    public function error(string $path, string $message): void
    {
        $this->errors[$path] ??= $message;
    }

    public function hasError(string $path): bool
    {
        return isset($this->errors[$path]);
    }

    public function throwIfInvalid(): void
    {
        if ($this->errors) {
            throw HttpException::validation($this->errors);
        }
    }

    public function text(string $path, int $max): ?string
    {
        $value = $this->raw($path);
        if (self::blank($value)) {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            $this->error($path, 'Invalid value.');
            return null;
        }
        $value = trim((string) $value);
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            $this->error($path, 'Contains invalid characters.');
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > $max) {
            $this->error($path, "Must be at most $max characters.");
            return null;
        }
        return $value;
    }

    public function int(string $path, int $min, int $max): ?int
    {
        $value = $this->raw($path);
        if (self::blank($value)) {
            return null;
        }
        $isWhole = is_int($value)
            || (is_string($value) && preg_match('/^-?\d{1,10}$/', trim($value)))
            || (is_float($value) && floor($value) === $value);
        if (!$isWhole) {
            $this->error($path, 'Must be a whole number.');
            return null;
        }
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            $this->error($path, "Must be between $min and $max.");
            return null;
        }
        return $value;
    }

    /** A number within range that is a multiple of $step (e.g. 0.25 dioptres). */
    public function decimal(string $path, float $min, float $max, float $step): ?float
    {
        $value = $this->raw($path);
        if (self::blank($value)) {
            return null;
        }
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric(trim($value)))) {
            $this->error($path, 'Must be a number.');
            return null;
        }
        $value = (float) $value;
        if ($value < $min || $value > $max) {
            $this->error($path, 'Must be between ' . self::number($min) . ' and ' . self::number($max) . '.');
            return null;
        }
        $steps = $value / $step;
        if (abs($steps - round($steps)) > 1e-6) {
            $this->error($path, 'Use steps of ' . self::number($step) . '.');
            return null;
        }
        return round(round($steps) * $step, 2);
    }

    public function enum(string $path, array $allowed): ?string
    {
        $value = $this->raw($path);
        if (self::blank($value)) {
            return null;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->error($path, 'Invalid choice.');
            return null;
        }
        return $value;
    }

    /** Snellen visual acuity, CF (with distance), HM, PL, NPL or Ø (no eye). */
    public function va(string $path): ?string
    {
        return $this->enum($path, ClinicalService::VA_VALUES);
    }

    public function bool(string $path): bool
    {
        $value = $this->raw($path);
        if ($value === null || $value === '') {
            return false;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            $this->error($path, 'Must be true or false.');
            return false;
        }
        return $parsed;
    }

    public function date(string $path, ?string $notBefore = null): ?string
    {
        $value = $this->raw($path);
        if (self::blank($value)) {
            return null;
        }
        if (!is_string($value) || !Format::isDate($value)) {
            $this->error($path, 'Use a valid date.');
            return null;
        }
        if ($notBefore !== null && $value < $notBefore) {
            $this->error($path, 'The date cannot be in the past.');
            return null;
        }
        return $value;
    }

    /** Distinct positive ids. */
    public function ids(string $path, int $max): array
    {
        $value = $this->raw($path);
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            $this->error($path, 'Invalid list.');
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            if (!(is_int($item) && $item > 0) && !(is_string($item) && preg_match('/^[1-9]\d{0,9}$/', $item))) {
                $this->error($path, 'Invalid list.');
                return [];
            }
            $ids[(int) $item] = true;
        }
        if (count($ids) > $max) {
            $this->error($path, "Choose at most $max.");
            return [];
        }
        return array_keys($ids);
    }

    /** A list of objects (e.g. prescription lines); read their fields with "$path.$index.field". */
    public function rows(string $path, int $max): array
    {
        $value = $this->raw($path);
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            $this->error($path, 'Invalid list.');
            return [];
        }
        if (count($value) > $max) {
            $this->error($path, "At most $max entries.");
            return [];
        }
        foreach ($value as $row) {
            if (!is_array($row)) {
                $this->error($path, 'Invalid list.');
                return [];
            }
        }
        return $value;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private static function number(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
