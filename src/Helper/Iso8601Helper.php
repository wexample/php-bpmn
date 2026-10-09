<?php

namespace Wexample\PhpBpmn\Helper;

use DateInterval;
use DateTimeImmutable;
use Throwable;
use Wexample\PhpBpmn\Enum\TimerKind;

/**
 * The ISO 8601 a timer is written in: a duration (`P3D`, `PT1H30M`), a date
 * (`2026-12-31T09:00:00Z`), a cycle (`R3/PT1H`, `R/2026-01-01T00:00:00Z/P1M`).
 */
final class Iso8601Helper
{
    public static function isValid(TimerKind $kind, string $expression): bool
    {
        return match ($kind) {
            TimerKind::DURATION => self::parseDuration($expression) !== null,
            TimerKind::DATE => self::parseDate($expression) !== null,
            TimerKind::CYCLE => self::parseCycle($expression) !== null,
        };
    }

    public static function parseDuration(string $expression): ?DateInterval
    {
        $expression = trim($expression);

        if (! str_starts_with($expression, 'P')) {
            return null;
        }

        try {
            return new DateInterval($expression);
        } catch (Throwable) {
            return null;
        }
    }

    public static function parseDate(string $expression): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(DATE_ATOM, trim($expression))
            ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', trim($expression), new \DateTimeZone('UTC'));

        return $date ?: null;
    }

    /**
     * @return array{repetitions: ?int, interval: DateInterval, start: ?DateTimeImmutable}|null
     */
    public static function parseCycle(string $expression): ?array
    {
        if (! preg_match('#^R(\d*)/(?:([^/]+)/)?(P[^/]+)$#', trim($expression), $match)) {
            return null;
        }

        $interval = self::parseDuration($match[3]);
        $start = $match[2] !== '' ? self::parseDate($match[2]) : null;

        if ($interval === null || ($match[2] !== '' && $start === null)) {
            return null;
        }

        return ['repetitions' => $match[1] === '' ? null : (int) $match[1], 'interval' => $interval, 'start' => $start];
    }

    /**
     * When a timer started at $from fires first.
     */
    public static function getFireDate(TimerKind $kind, string $expression, DateTimeImmutable $from): ?DateTimeImmutable
    {
        return match ($kind) {
            TimerKind::DURATION => ($interval = self::parseDuration($expression)) ? $from->add($interval) : null,
            TimerKind::DATE => self::parseDate($expression),
            TimerKind::CYCLE => ($cycle = self::parseCycle($expression)) ? max($cycle['start'] ?? $from, $from)->add($cycle['interval']) : null,
        };
    }
}
