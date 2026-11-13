<?php

declare(strict_types=1);

namespace App\Collector\Docker;

/** Parses Docker list `Status` text for HEALTHCHECK state (no extra inspect call). */
final class DockerHealthStatus
{
    /**
     * @return 'healthy'|'unhealthy'|'starting'|null null = no healthcheck in status text
     */
    public static function fromDockerStatus(string $status): ?string
    {
        $status = trim($status);
        if ('' === $status) {
            return null;
        }

        if (preg_match('/\((healthy|unhealthy|starting)\)/i', $status, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }
}
