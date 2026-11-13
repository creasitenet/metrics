<?php

declare(strict_types=1);

namespace App\Metrics;

final class MetricResolution
{
    public const SECONDS_30 = '30s';
    public const MINUTES_5 = '5m';
    public const HOURS_1 = '1h';

    public static function bucketStart(\DateTimeImmutable $at, string $resolution, int $collectorInterval): \DateTimeImmutable
    {
        $ts = $at->getTimestamp();

        return match ($resolution) {
            self::SECONDS_30 => new \DateTimeImmutable('@'.((int) floor($ts / max(1, $collectorInterval)) * max(1, $collectorInterval))),
            self::MINUTES_5 => new \DateTimeImmutable('@'.((int) floor($ts / 300) * 300)),
            self::HOURS_1 => new \DateTimeImmutable('@'.((int) floor($ts / 3600) * 3600)),
            default => throw new \InvalidArgumentException(\sprintf('Unknown resolution "%s".', $resolution)),
        };
    }

    /**
     * Resolutions written on each collect (dashboard uses the 1h / 30s window only).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::SECONDS_30];
    }
}
