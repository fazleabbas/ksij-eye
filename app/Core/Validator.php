<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Http\HttpException;

/**
 * Declarative input validation. Collects every field error, then validate() either
 * returns the cleaned values or throws a 422 with per-field messages.
 */
final class Validator
{
    private array $errors = [];
    private array $clean = [];

    private function __construct(private readonly array $data)
    {
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    public function string(string $field, bool $required = true, int $max = 255, int $min = 0, ?string $pattern = null, bool $trim = true, ?string $message = null): self
    {
        $value = $this->data[$field] ?? null;
        if ($this->isMissing($value)) {
            return $this->missing($field, $required);
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return $this->fail($field, 'Invalid value.');
        }
        $value = (string) $value;
        if ($trim) {
            $value = trim($value);
        }
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            return $this->fail($field, 'Contains invalid characters.');
        }
        $length = mb_strlen($value, 'UTF-8');
        if ($length < $min) {
            return $this->fail($field, "Must be at least $min characters.");
        }
        if ($length > $max) {
            return $this->fail($field, "Must be at most $max characters.");
        }
        if ($pattern !== null && !preg_match($pattern, $value)) {
            return $this->fail($field, $message ?? 'Invalid format.');
        }
        $this->clean[$field] = $value;
        return $this;
    }

    public function email(string $field, bool $required = false): self
    {
        $this->string($field, $required, 190);
        if (isset($this->clean[$field]) && filter_var($this->clean[$field], FILTER_VALIDATE_EMAIL) === false) {
            unset($this->clean[$field]);
            $this->fail($field, 'Enter a valid email address.');
        }
        return $this;
    }

    public function int(string $field, bool $required = true, ?int $min = null, ?int $max = null): self
    {
        $value = $this->data[$field] ?? null;
        if ($this->isMissing($value)) {
            return $this->missing($field, $required);
        }
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d{1,18}$/', trim($value)))) {
            return $this->fail($field, 'Must be a whole number.');
        }
        $value = (int) $value;
        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            return $this->fail($field, 'Must be between ' . ($min ?? '−∞') . ' and ' . ($max ?? '∞') . '.');
        }
        $this->clean[$field] = $value;
        return $this;
    }

    public function decimal(string $field, bool $required, float $min, float $max): self
    {
        $value = $this->data[$field] ?? null;
        if ($this->isMissing($value)) {
            return $this->missing($field, $required);
        }
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric(trim($value)))) {
            return $this->fail($field, 'Must be a number.');
        }
        $value = round((float) $value, 2);
        if ($value < $min || $value > $max) {
            return $this->fail($field, "Must be between $min and $max.");
        }
        $this->clean[$field] = $value;
        return $this;
    }

    public function bool(string $field, bool $required = false): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return $this->missing($field, $required);
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            return $this->fail($field, 'Must be true or false.');
        }
        $this->clean[$field] = $parsed;
        return $this;
    }

    public function enum(string $field, array $allowed, bool $required = true): self
    {
        $value = $this->data[$field] ?? null;
        if ($this->isMissing($value)) {
            return $this->missing($field, $required);
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            return $this->fail($field, 'Invalid choice.');
        }
        $this->clean[$field] = $value;
        return $this;
    }

    public function intArray(string $field, bool $required = false, int $minItems = 0, int $maxItems = 100): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return $required ? $this->fail($field, 'This field is required.') : $this->set($field, []);
        }
        if (!is_array($value) || !array_is_list($value)) {
            return $this->fail($field, 'Invalid list.');
        }
        $ints = [];
        foreach ($value as $item) {
            if (!is_int($item) && !(is_string($item) && ctype_digit($item))) {
                return $this->fail($field, 'Invalid list.');
            }
            $ints[] = (int) $item;
        }
        $ints = array_values(array_unique($ints));
        sort($ints);
        if (count($ints) < $minItems) {
            return $this->fail($field, $minItems === 1 ? 'Select at least one.' : "Select at least $minItems.");
        }
        if (count($ints) > $maxItems) {
            return $this->fail($field, "Select at most $maxItems.");
        }
        return $this->set($field, $ints);
    }

    public function stringArray(string $field, string $pattern, bool $required = false, int $minItems = 0, int $maxItems = 500): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null) {
            return $required ? $this->fail($field, 'This field is required.') : $this->set($field, []);
        }
        if (!is_array($value) || !array_is_list($value)) {
            return $this->fail($field, 'Invalid list.');
        }
        foreach ($value as $item) {
            if (!is_string($item) || !preg_match($pattern, $item)) {
                return $this->fail($field, 'Invalid list.');
            }
        }
        $value = array_values(array_unique($value));
        if (count($value) < $minItems) {
            return $this->fail($field, $minItems === 1 ? 'Select at least one.' : "Select at least $minItems.");
        }
        if (count($value) > $maxItems) {
            return $this->fail($field, "Select at most $maxItems.");
        }
        return $this->set($field, $value);
    }

    public function validate(): array
    {
        if ($this->errors) {
            throw HttpException::validation($this->errors);
        }
        return $this->clean;
    }

    private function isMissing(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function missing(string $field, bool $required): self
    {
        return $required ? $this->fail($field, 'This field is required.') : $this->set($field, null);
    }

    private function set(string $field, mixed $value): self
    {
        $this->clean[$field] = $value;
        return $this;
    }

    private function fail(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;
        return $this;
    }
}
