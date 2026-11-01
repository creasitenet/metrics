<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Collector\Docker\DockerMetricsMapper;
use PHPUnit\Framework\TestCase;

final class DockerMetricsMapperTest extends TestCase
{
    public function testItMapsHostAndContainerMetricsWithComposeLabels(): void
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

        $collection = (new DockerMetricsMapper())->map($info, $containers, $stats, $now);

        $snapshot = $collection->containers['abcdef1234567890'];
        self::assertSame('api', $snapshot->name);
        self::assertSame('shop', $snapshot->composeProject);
        self::assertSame('api', $snapshot->composeService);
        self::assertNull($snapshot->composeWorkingDir);
        self::assertNull($snapshot->group);

        $sample = $collection->sampleFor('abcdef1234567890');
        self::assertNotNull($sample);
        self::assertTrue($sample->running);
        // Prefer inactive_file over legacy cache (100M would have left only 20M).
        self::assertSame(100_000_000, $sample->memoryUsedBytes);
        self::assertSame(512_000_000, $sample->memoryLimitBytes);
        self::assertSame(40.0, $sample->cpuPercent);

        $host = $collection->host;
        self::assertNotNull($host);
        self::assertSame(40.0, $host->cpuPercent);
        self::assertSame(4, $host->cpuCount);
        self::assertSame(100_000_000, $host->memoryUsedBytes);
        self::assertSame(2, $host->containersTotal);
        self::assertSame(1, $host->containersRunning);
        self::assertSame(1, $host->containersStopped);
    }

    public function testItCollectsDozzleAndComposeFolderLabels(): void
    {
        $now = new \DateTimeImmutable('2026-07-24T10:00:00+00:00');
        $info = ['Containers' => 1, 'ContainersRunning' => 1, 'NCPU' => 2, 'MemTotal' => 1_000_000_000];
        $containers = [[
            'Id' => 'abcdef1234567890',
            'Names' => ['/api'],
            'Image' => 'api:1',
            'State' => 'running',
            'Labels' => [
                'com.docker.compose.project' => 'shop',
                'com.docker.compose.service' => 'api',
                'com.docker.compose.project.working_dir' => '/srv/shop',
                'com.docker.compose.project.config_files' => '/srv/shop/compose.yaml',
                'dev.dozzle.group' => 'storefront',
            ],
        ]];

        $collection = (new DockerMetricsMapper())->map($info, $containers, [], $now);

        $snapshot = $collection->containers['abcdef1234567890'];
        self::assertSame('/srv/shop', $snapshot->composeWorkingDir);
        self::assertSame('/srv/shop/compose.yaml', $snapshot->composeConfigFiles);
        self::assertSame('storefront', $snapshot->group);
    }

    public function testStoppedContainerHasNoCpuOrMemoryMetrics(): void
    {
        $now = new \DateTimeImmutable('2026-07-24T10:00:00+00:00');
        $info = ['Containers' => 1, 'ContainersRunning' => 0, 'NCPU' => 2, 'MemTotal' => 1_000_000_000];
        $containers = [[
            'Id' => 'abcdef1234567890',
            'Names' => ['/api'],
            'Image' => 'api:1',
            'State' => 'exited',
            'Labels' => [],
        ]];
        $stats = [
            'abcdef1234567890' => [
                'cpu_stats' => [
                    'cpu_usage' => ['total_usage' => 2000],
                    'system_cpu_usage' => 10_000,
                    'online_cpus' => 1,
                ],
                'precpu_stats' => [
                    'cpu_usage' => ['total_usage' => 1000],
                    'system_cpu_usage' => 5_000,
                ],
                'memory_stats' => ['usage' => 1_000, 'limit' => 1_000_000],
            ],
        ];

        $collection = (new DockerMetricsMapper())->map($info, $containers, $stats, $now);

        $sample = $collection->sampleFor('abcdef1234567890');
        self::assertNotNull($sample);
        self::assertFalse($sample->running);
        self::assertNull($sample->cpuPercent);
        self::assertNull($sample->memoryUsedBytes);
        self::assertNull($sample->memoryLimitBytes);
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

        $collection = (new DockerMetricsMapper())->map($info, $containers, $stats, $now);

        self::assertNotNull($collection->host);
        self::assertSame(60.0, $collection->host->cpuPercent);
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

        $collection = (new DockerMetricsMapper())->map($info, $containers, $stats, $now);

        $sample = $collection->sampleFor('cccc');
        self::assertNotNull($sample);
        self::assertSame(2_700_000_000, $sample->memoryUsedBytes);
    }

    public function testMemoryLimitFallsBackToHostTotalWhenContainerHasNoLimit(): void
    {
        $now = new \DateTimeImmutable('2026-07-24T10:00:00+00:00');
        $info = ['Containers' => 1, 'ContainersRunning' => 1, 'NCPU' => 1, 'MemTotal' => 4_000_000_000];
        $containers = [[
            'Id' => 'dddd',
            'Names' => ['/app'],
            'Image' => 'app',
            'State' => 'running',
            'Labels' => [],
        ]];
        $stats = [
            'dddd' => [
                'cpu_stats' => [
                    'cpu_usage' => ['total_usage' => 2000],
                    'system_cpu_usage' => 10_000,
                    'online_cpus' => 1,
                ],
                'precpu_stats' => [
                    'cpu_usage' => ['total_usage' => 1000],
                    'system_cpu_usage' => 5_000,
                ],
                'memory_stats' => ['usage' => 1_000, 'limit' => 8_000_000_000],
            ],
        ];

        $collection = (new DockerMetricsMapper())->map($info, $containers, $stats, $now);

        $sample = $collection->sampleFor('dddd');
        self::assertNotNull($sample);
        self::assertSame(4_000_000_000, $sample->memoryLimitBytes);
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
