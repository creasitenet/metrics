<?php

declare(strict_types=1);

namespace App\Collector\Docker;

/** Parses Docker list `Status` text (e.g. "Up 4 days (healthy)"). */
final class DockerContainerUptime
{
    public static function fromDockerStatus(string $status): ?string
    {
        $status = trim($status);
        if ('' === $status || !preg_match('/^Up\s+(.+?)(?:\s*\(|$)/i', $status, $matches)) {
            return null;
        }

        $span = trim($matches[1]);
        if ('' === $span) {
            return null;
        }

        return $span;
    }
}
