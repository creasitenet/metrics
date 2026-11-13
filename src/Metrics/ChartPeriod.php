<?php

declare(strict_types=1);

namespace App\Metrics;

/** Dashboard charts use a single 1-hour window. */
final class ChartPeriod
{
    public const DEFAULT = '1h';

    public const PERIODS = [
        self::DEFAULT => ['label' => '1 hour', 'since' => '-1 hour'],
    ];

    public static function resolve(string $period): string
    {
        return isset(self::PERIODS[$period]) ? $period : self::DEFAULT;
    }

    public static function since(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::PERIODS[self::DEFAULT]['since']);
    }

    public static function preferredResolution(): string
    {
        return MetricResolution::SECONDS_30;
    }
}
