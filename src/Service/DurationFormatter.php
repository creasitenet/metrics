<?php

declare(strict_types=1);

namespace App\Service;

final class DurationFormatter
{
    public static function humanShort(?int $seconds): ?string
    {
        if (null === $seconds || $seconds < 60) {
            return null;
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return 1 === $days ? '1 day' : \sprintf('%d days', $days);
        }
        if ($hours > 0) {
            return 1 === $hours ? '1 hour' : \sprintf('%d hours', $hours);
        }

        return 1 === $minutes ? '1 minute' : \sprintf('%d minutes', $minutes);
    }
}
