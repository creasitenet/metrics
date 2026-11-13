<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Collector\Docker\DockerContainerUptime;
use PHPUnit\Framework\TestCase;

final class DockerContainerUptimeTest extends TestCase
{
    public function testParsesRunningDuration(): void
    {
        self::assertSame('4 days', DockerContainerUptime::fromDockerStatus('Up 4 days'));
        self::assertSame('2 hours', DockerContainerUptime::fromDockerStatus('Up 2 hours (healthy)'));
    }

    public function testIgnoresExited(): void
    {
        self::assertNull(DockerContainerUptime::fromDockerStatus('Exited (0) 2 hours ago'));
    }
}
