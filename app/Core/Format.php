<?php
declare(strict_types=1);

namespace App\Core;

final class Format
{
    public const LOCAL_TZ = 'Africa/Dar_es_Salaam';

    /** DB UTC DATETIME → ISO-8601 with Z (the browser renders it in local time). */
    public static function utc(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, $utc)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $utc);
        return $dt ? $dt->format('Y-m-d\TH:i:s.v\Z') : null;
    }

    /** Local (EAT) calendar date → UTC DATETIME string for its start, or the start of the next day. */
    public static function localDateToUtc(string $ymd, bool $endExclusive = false): string
    {
        $dt = new \DateTimeImmutable($ymd . ' 00:00:00', new \DateTimeZone(self::LOCAL_TZ));
        if ($endExclusive) {
            $dt = $dt->modify('+1 day');
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Today's calendar date in the hospital's time zone (Y-m-d). */
    public static function localToday(int $offsetDays = 0): string
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone(self::LOCAL_TZ));
        return ($offsetDays ? $today->modify(($offsetDays > 0 ? '+' : '') . $offsetDays . ' days') : $today)->format('Y-m-d');
    }

    /** Ymd key for daily numbering (VISIT 20260913/001, PAY-20260913-001). */
    public static function localDateKey(): string
    {
        return str_replace('-', '', self::localToday());
    }

    /** Completed years between a date of birth and a date (both Y-m-d). */
    public static function ageOn(string $dateOfBirth, string $onDate): int
    {
        if (!self::isDate($dateOfBirth) || !self::isDate($onDate)) {
            return 0;
        }
        return max(0, (new \DateTimeImmutable($dateOfBirth))->diff(new \DateTimeImmutable($onDate))->y);
    }

    public static function localTodayUtcStart(): string
    {
        return self::localDateToUtc((new \DateTimeImmutable('now', new \DateTimeZone(self::LOCAL_TZ)))->format('Y-m-d'));
    }

    public static function fullName(?string ...$parts): string
    {
        return trim(implode(' ', array_filter($parts, static fn ($p) => $p !== null && trim($p) !== '')));
    }

    public static function initials(?string $first, ?string $last): string
    {
        $initials = mb_substr(trim((string) $first), 0, 1) . mb_substr(trim((string) $last), 0, 1);
        return mb_strtoupper($initials) ?: 'U';
    }

    public static function isDate(string $value): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    }
}
