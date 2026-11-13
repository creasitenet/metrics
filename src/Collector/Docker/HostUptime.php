<?php

declare(strict_types=1);

namespace App\Collector\Docker;

/** Host uptime from /proc/uptime when the collector runs on Linux (null on Docker Desktop etc.). */
final class HostUptime
{
    public static function seconds(): ?int
    {
        if (!is_readable('/proc/uptime')) {
            return null;
        }

        $raw = file_get_contents('/proc/uptime');
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        $parts = explode(' ', trim($raw), 2);
        if (!is_numeric($parts[0])) {
            return null;
        }

        $seconds = (int) floor((float) $parts[0]);

        return $seconds > 0 ? $seconds : null;
    }
}
