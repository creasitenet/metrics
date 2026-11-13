<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Container;
use App\Entity\HostState;
use App\Service\SeriesBuilder;
use PHPUnit\Framework\TestCase;

final class SeriesBuilderTest extends TestCase
{
    public function testItBuildsHostTilesFromStateAndMergesChartSeries(): void
    {
        $at = new \DateTimeImmutable('2026-01-01T10:00:00+00:00');
        $hostState = new HostState(98.2, 1100, 8192, 4, 3, 2, 1, 10, 170, 166, $at);
        $chart = [
            SeriesBuilder::HOST_CPU_PERCENT => [
                'labels' => [$at->format(\DATE_ATOM)],
                'values' => [120.5],
                'latest' => 120.5,
                'count' => 1,
            ],
        ];

        $dashboard = (new SeriesBuilder())->buildDashboard($hostState, [], $chart);

        self::assertSame([120.5], $dashboard['host']['docker.host.cpu_percent']['values']);
        self::assertSame(2.0, $dashboard['host']['docker.containers.running']['latest']);
    }

    public function testItBuildsContainerRowsFromLatestGaugesOnEntity(): void
    {
        $container = new Container('abc', 'api', 'api:1', 'running', 'Up 2 minutes', 'shop', 'api');
        $container->applyLatestSample(true, 11.0, 1024, 4096, new \DateTimeImmutable('2026-01-01T10:00:00+00:00'));

        $dashboard = (new SeriesBuilder())->buildDashboard(null, [$container], []);

        self::assertArrayHasKey('abc', $dashboard['containers']);
        $row = $dashboard['containers']['abc'];
        self::assertSame('api', $row['name']);
        self::assertTrue($row['running']);
        self::assertSame(11.0, $row['cpu_percent']);
        self::assertSame(1024, $row['memory_used_bytes']);
    }

    public function testContainerWithoutSampleIsReportedAsStopped(): void
    {
        $container = new Container('abc', 'api', 'api:1', 'exited', 'Exited (0) 2 minutes ago');

        $dashboard = (new SeriesBuilder())->buildDashboard(null, [$container], []);

        $row = $dashboard['containers']['abc'];
        self::assertFalse($row['running']);
        self::assertNull($row['cpu_percent']);
    }
}
