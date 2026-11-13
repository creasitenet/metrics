<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Collector\Docker\DockerHealthStatus;
use PHPUnit\Framework\TestCase;

final class DockerHealthStatusTest extends TestCase
{
    public function testParsesHealthFromStatusText(): void
    {
        self::assertSame('healthy', DockerHealthStatus::fromDockerStatus('Up 2 hours (healthy)'));
        self::assertSame('unhealthy', DockerHealthStatus::fromDockerStatus('Up 1 minute (unhealthy)'));
        self::assertSame('starting', DockerHealthStatus::fromDockerStatus('Up 5 seconds (starting)'));
        self::assertNull(DockerHealthStatus::fromDockerStatus('Exited (0) 2 minutes ago'));
    }
}
