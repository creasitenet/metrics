<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Container;
use App\Entity\ContainerSample;
use App\Entity\HostSample;
use App\Service\SeriesBuilder;
use PHPUnit\Framework\TestCase;

final class SeriesBuilderTest extends TestCase
{
    public function testItGroupsHostSamplesByMetricAndKeepsTheLatestValue(): void
    {
        $history = [
            new HostSample(new \DateTimeImmutable('2026-01-01T10:00:00+00:00'), 120.5, 1000, 8192, 4, 3, 2, 1, 10, 170, 166),
            new HostSample(new \DateTimeImmutable('2026-01-01T10:01:00+00:00'), 98.2, 1100, 8192, 4, 3, 2, 1, 10, 170, 166),
        ];

        $dashboard = (new SeriesBuilder())->buildDashboard([], [], $history);

        self::assertSame([120.5, 98.2], $dashboard['host']['docker.host.cpu_percent']['values']);
        self::assertSame(98.2, $dashboard['host']['docker.host.cpu_percent']['latest']);
        self::assertSame(2, $dashboard['host']['docker.host.cpu_percent']['count']);
        self::assertSame(2.0, $dashboard['host']['docker.containers.running']['latest']);
    }

    public function testItBuildsOneRowPerContainerWithItsLatestSample(): void
    {
        $container = new Container('abc', 'api', 'api:1', 'running', 'Up 2 minutes', 'shop', 'api');
        $sample = new ContainerSample('abc', new \DateTimeImmutable('2026-01-01T10:00:00+00:00'), true, 11.0, 1024, 4096);

        $dashboard = (new SeriesBuilder())->buildDashboard([$container], ['abc' => $sample], []);

        self::assertArrayHasKey('abc', $dashboard['containers']);
        $row = $dashboard['containers']['abc'];
        self::assertSame('api', $row['name']);
        self::assertSame('shop', $row['compose_project']);
        self::assertSame('api', $row['compose_service']);
        self::assertTrue($row['running']);
        self::assertSame(11.0, $row['cpu_percent']);
        self::assertSame(1024, $row['memory_used_bytes']);
        self::assertSame(4096, $row['memory_limit_bytes']);
    }

    public function testContainerWithoutSampleIsReportedAsStopped(): void
    {
        $container = new Container('abc', 'api', 'api:1', 'exited', 'Exited (0) 2 minutes ago');

        $dashboard = (new SeriesBuilder())->buildDashboard([$container], [], []);

        $row = $dashboard['containers']['abc'];
        self::assertFalse($row['running']);
        self::assertNull($row['cpu_percent']);
        self::assertNull($row['memory_used_bytes']);
        self::assertNull($row['memory_limit_bytes']);
    }

    public function testBuildContainerSeriesSkipsNullValues(): void
    {
        $samples = [
            new ContainerSample('abc', new \DateTimeImmutable('2026-01-01T10:00:00+00:00'), true, 11.0, 1024, 4096),
            new ContainerSample('abc', new \DateTimeImmutable('2026-01-01T10:01:00+00:00'), false, null, null, null),
        ];

        $series = (new SeriesBuilder())->buildContainerSeries($samples);

        self::assertSame([11.0], $series['docker.container.cpu_percent']['values']);
        self::assertSame([1024.0], $series['docker.container.memory_used_bytes']['values']);
        self::assertSame([4096.0], $series['docker.container.memory_limit_bytes']['values']);
        self::assertSame([1.0, 0.0], $series['docker.container.running']['values']);
    }
}
