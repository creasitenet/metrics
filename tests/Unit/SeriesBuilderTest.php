<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\SeriesBuilder;
use PHPUnit\Framework\TestCase;

final class SeriesBuilderTest extends TestCase
{
    public function testItGroupsHostPointsByMetricAndKeepsTheLatestValue(): void
    {
        $points = [
            $this->point('docker.host.cpu_percent', 120.5, [], '2026-01-01T10:00:00+00:00'),
            $this->point('docker.host.cpu_percent', 98.2, [], '2026-01-01T10:01:00+00:00'),
            $this->point('docker.containers.running', 4, [], '2026-01-01T10:01:00+00:00'),
        ];

        $dashboard = (new SeriesBuilder())->buildDashboard([], $points);

        self::assertSame([120.5, 98.2], $dashboard['host']['docker.host.cpu_percent']['values']);
        self::assertSame(98.2, $dashboard['host']['docker.host.cpu_percent']['latest']);
        self::assertSame(2, $dashboard['host']['docker.host.cpu_percent']['count']);
        self::assertSame(4.0, $dashboard['host']['docker.containers.running']['latest']);
    }

    public function testItSplitsDockerContainerSeriesByContainerName(): void
    {
        $points = [
            $this->point('docker.containers.running', 2, [], '2026-01-01T10:00:00+00:00'),
            $this->point('docker.container.cpu_percent', 11.0, [
                'container_name' => 'api',
                'image' => 'api:1',
                'container_id' => 'abc',
                'compose_service' => 'api',
            ], '2026-01-01T10:00:00+00:00'),
            $this->point('docker.container.cpu_percent', 22.0, [
                'container_name' => 'web',
                'image' => 'web:1',
                'container_id' => 'def',
                'compose_service' => 'web',
            ], '2026-01-01T10:00:00+00:00'),
        ];

        $dashboard = (new SeriesBuilder())->buildDashboard([], $points);

        self::assertArrayHasKey('docker.containers.running', $dashboard['host']);
        self::assertSame(['api', 'web'], array_keys($dashboard['containers']));
        self::assertSame(11.0, $dashboard['containers']['api']['metrics']['docker.container.cpu_percent']['latest']);
        self::assertSame(22.0, $dashboard['containers']['web']['metrics']['docker.container.cpu_percent']['latest']);
        self::assertSame('api', $dashboard['containers']['api']['compose_service']);
    }

    public function testHostStatesBecomeLatestWithoutHistory(): void
    {
        $states = [[
            'value' => 3.0,
            'tags' => [],
            'metric' => ['name' => 'docker.containers.running'],
        ]];

        $dashboard = (new SeriesBuilder())->buildDashboard($states, []);

        self::assertSame(3.0, $dashboard['host']['docker.containers.running']['latest']);
        self::assertSame(0, $dashboard['host']['docker.containers.running']['count']);
    }

    /**
     * @param array<string, scalar|null> $tags
     *
     * @return array<string, mixed>
     */
    private function point(string $metric, float $value, array $tags, string $recordedAt): array
    {
        return [
            'value' => $value,
            'tags' => $tags,
            'recordedAt' => new \DateTimeImmutable($recordedAt),
            'metric' => ['name' => $metric],
        ];
    }
}
