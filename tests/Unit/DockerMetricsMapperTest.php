<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Collector\Docker\DockerMetricsMapper;
use App\Enum\MetricType;
use PHPUnit\Framework\TestCase;

final class DockerMetricsMapperTest extends TestCase
{
    public function testItMapsHostAndContainerGaugesWithComposeLabels(): void
    {
        $now = new \DateTimeImmutable('2026-07-24T10:00:00+00:00');
        $info = [
            'Containers' => 2,
            'ContainersRunning' => 1,
            'ContainersPaused' => 0,
            'ContainersStopped' => 1,
            'NCPU' => 4,
            'MemTotal' => 8_589_934_592,
        ];
        $containers = [[
            'Id' => 'abcdef1234567890',
            'Names' => ['/api'],
            'Image' => 'ghcr.io/example/api:1',
            'State' => 'running',
            'Labels' => [
                'com.docker.compose.project' => 'shop',
                'com.docker.compose.service' => 'api',
            ],
        ]];
        $stats = [
            'abcdef1234567890' => [
                'cpu_stats' => [
                    'cpu_usage' => ['total_usage' => 2000, 'percpu_usage' => [1, 1]],
                    'system_cpu_usage' => 10_000,
                    'online_cpus' => 2,
                ],
                'precpu_stats' => [
                    'cpu_usage' => ['total_usage' => 1000],
                    'system_cpu_usage' => 5_000,
                ],
                'memory_stats' => [
                    'usage' => 120_000_000,
                    'limit' => 512_000_000,
                    'stats' => [
                        'cache' => 100_000_000,
                        'inactive_file' => 20_000_000,
                    ],
                ],
            ],
        ];

        $points = (new DockerMetricsMapper())->map($info, $containers, $stats, $now);
        $byMetric = [];
        foreach ($points as $point) {
            $byMetric[$point->metric][] = $point;
        }

        self::assertSame(1.0, $byMetric['docker.containers.running'][0]->value);
        self::assertSame(MetricType::STATE, $byMetric['docker.containers.running'][0]->type);
        self::assertSame(2.0, $byMetric['docker.containers.total'][0]->value);
        self::assertSame(1.0, $byMetric['docker.containers.stopped'][0]->value);
        // Prefer inactive_file over legacy cache (100M would have left only 20M).
        self::assertSame(100_000_000.0, $byMetric['docker.container.memory_used_bytes'][0]->value);
        self::assertSame(512_000_000.0, $byMetric['docker.container.memory_limit_bytes'][0]->value);
        self::assertSame('api', $byMetric['docker.container.cpu_percent'][0]->tags['container_name']);
        self::assertSame('shop', $byMetric['docker.container.cpu_percent'][0]->tags['compose_project']);
        self::assertSame('api', $byMetric['docker.container.cpu_percent'][0]->tags['compose_service']);
        self::assertSame(40.0, $byMetric['docker.container.cpu_percent'][0]->value);
        self::assertSame(40.0, $byMetric['docker.host.cpu_percent'][0]->value);
        self::assertSame(4.0, $byMetric['docker.host.cpu_count'][0]->value);
        self::assertSame(100_000_000.0, $byMetric['docker.host.memory_used_bytes'][0]->value);
    }

    public function testHostCpuPercentIsSumOfContainersNotAverage(): void
    {
        $now = new \DateTimeImmutable('2026-07-24T10:00:00+00:00');
        $info = ['Containers' => 2, 'ContainersRunning' => 2, 'NCPU' => 8, 'MemTotal' => 1_000_000_000];
        $containers = [
            ['Id' => 'aaaa', 'Names' => ['/a'], 'Image' => 'a', 'State' => 'running', 'Labels' => []],
            ['Id' => 'bbbb', 'Names' => ['/b'], 'Image' => 'b', 'State' => 'running', 'Labels' => []],
        ];
        $stats = [
            'aaaa' => $this->statsWithCpu(1000, 2000, 5_000, 10_000, 2), // 40%
            'bbbb' => $this->statsWithCpu(1000, 1500, 5_000, 10_000, 2), // 20%
        ];

        $points = (new DockerMetricsMapper())->map($info, $containers, $stats, $now);
        $hostCpu = null;
        foreach ($points as $point) {
            if ('docker.host.cpu_percent' === $point->metric) {
                $hostCpu = $point->value;
            }
        }

        self::assertSame(60.0, $hostCpu);
    }

    public function testMemoryPrefersTotalInactiveFileOnCgroupV1(): void
    {
        $now = new \DateTimeImmutable('2026-07-24T10:00:00+00:00');
        $info = ['Containers' => 1, 'ContainersRunning' => 1, 'MemTotal' => 8_000_000_000];
        $containers = [[
            'Id' => 'cccc',
            'Names' => ['/db'],
            'Image' => 'postgres',
            'State' => 'running',
            'Labels' => [],
        ]];
        $stats = [
            'cccc' => [
                'cpu_stats' => [
                    'cpu_usage' => ['total_usage' => 2000],
                    'system_cpu_usage' => 10_000,
                    'online_cpus' => 1,
                ],
                'precpu_stats' => [
                    'cpu_usage' => ['total_usage' => 1000],
                    'system_cpu_usage' => 5_000,
                ],
                'memory_stats' => [
                    'usage' => 2_900_000_000,
                    'limit' => 8_000_000_000,
                    'stats' => [
                        'cache' => 2_800_000_000,
                        'total_inactive_file' => 200_000_000,
                    ],
                ],
            ],
        ];

        $points = (new DockerMetricsMapper())->map($info, $containers, $stats, $now);
        $mem = null;
        foreach ($points as $point) {
            if ('docker.container.memory_used_bytes' === $point->metric) {
                $mem = $point->value;
            }
        }

        self::assertSame(2_700_000_000.0, $mem);
    }

    /**
     * @return array<string, mixed>
     */
    private function statsWithCpu(int $preTotal, int $total, int $preSystem, int $system, int $online): array
    {
        return [
            'cpu_stats' => [
                'cpu_usage' => ['total_usage' => $total],
                'system_cpu_usage' => $system,
                'online_cpus' => $online,
            ],
            'precpu_stats' => [
                'cpu_usage' => ['total_usage' => $preTotal],
                'system_cpu_usage' => $preSystem,
            ],
            'memory_stats' => [
                'usage' => 1_000_000,
                'limit' => 1_000_000_000,
                'stats' => ['inactive_file' => 0],
            ],
        ];
    }
}
