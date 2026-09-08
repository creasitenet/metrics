<?php

declare(strict_types=1);

namespace App\Collector\Docker;

use App\Dto\MetricPoint;
use App\Enum\MetricType;

final class DockerMetricsMapper
{
    /**
     * @param array<string, mixed>                $info
     * @param list<array<string, mixed>>          $containers
     * @param array<string, array<string, mixed>> $statsById
     *
     * @return list<MetricPoint>
     */
    public function map(array $info, array $containers, array $statsById, \DateTimeImmutable $now): array
    {
        $points = [];
        $total = (int) ($info['Containers'] ?? \count($containers));
        $running = (int) ($info['ContainersRunning'] ?? 0);
        $paused = (int) ($info['ContainersPaused'] ?? 0);
        $stopped = (int) ($info['ContainersStopped'] ?? max(0, $total - $running - $paused));
        $hostNcpu = (float) ($info['NCPU'] ?? 0);
        $hostMemoryTotal = (float) ($info['MemTotal'] ?? 0);
        $hostMemoryUsed = 0.0;
        $hostCpuSum = 0.0;
        $hostCpuSamples = 0;

        foreach ($containers as $container) {
            $id = (string) ($container['Id'] ?? '');
            if ('' === $id || !isset($statsById[$id])) {
                continue;
            }

            $tags = $this->containerTags($container);
            $isRunning = 'running' === ($container['State'] ?? '');

            if (!$isRunning) {
                $points[] = new MetricPoint('docker.container.running', MetricType::STATE, 0.0, $now, $tags);
                $points[] = new MetricPoint('docker.container.cpu_percent', MetricType::GAUGE, 0.0, $now, $tags);
                $points[] = new MetricPoint('docker.container.memory_used_bytes', MetricType::GAUGE, 0.0, $now, $tags);
                continue;
            }

            $stats = $statsById[$id];
            $cpu = $this->cpuPercent($stats);
            $memoryUsed = $this->memoryUsedBytes($stats);
            $memoryLimit = $this->memoryLimitBytes($stats, $hostMemoryTotal);

            $points[] = new MetricPoint('docker.container.running', MetricType::STATE, 1.0, $now, $tags);
            $points[] = new MetricPoint('docker.container.memory_limit_bytes', MetricType::STATE, $memoryLimit, $now, $tags);
            $points[] = new MetricPoint('docker.container.memory_used_bytes', MetricType::GAUGE, $memoryUsed, $now, $tags);

            $hostMemoryUsed += $memoryUsed;
            if (null !== $cpu) {
                $hostCpuSum += $cpu;
                ++$hostCpuSamples;
                $points[] = new MetricPoint('docker.container.cpu_percent', MetricType::GAUGE, $cpu, $now, $tags);
            }
        }

        $points[] = new MetricPoint('docker.containers.total', MetricType::STATE, (float) $total, $now);
        $points[] = new MetricPoint('docker.containers.running', MetricType::STATE, (float) $running, $now);
        $points[] = new MetricPoint('docker.containers.stopped', MetricType::STATE, (float) $stopped, $now);
        $points[] = new MetricPoint('docker.host.memory_total_bytes', MetricType::STATE, $hostMemoryTotal, $now);
        $points[] = new MetricPoint('docker.host.memory_used_bytes', MetricType::GAUGE, $hostMemoryUsed, $now);

        if ($hostNcpu > 0) {
            $points[] = new MetricPoint('docker.host.cpu_count', MetricType::STATE, $hostNcpu, $now);
        }
        if (isset($info['Images'])) {
            $points[] = new MetricPoint('docker.host.images_total', MetricType::STATE, (float) $info['Images'], $now);
        }
        if (isset($info['NGoroutines'])) {
            $points[] = new MetricPoint('docker.host.goroutines', MetricType::GAUGE, (float) $info['NGoroutines'], $now);
        }
        if (isset($info['NFd'])) {
            $points[] = new MetricPoint('docker.host.fds_open', MetricType::GAUGE, (float) $info['NFd'], $now);
        }
        // Sum of per-container % (same scale as `docker stats`: 100% = one full CPU).
        if ($hostCpuSamples > 0) {
            $points[] = new MetricPoint('docker.host.cpu_percent', MetricType::GAUGE, $hostCpuSum, $now);
        }

        return $points;
    }

    /**
     * @param array<string, mixed> $container
     *
     * @return array<string, scalar|null>
     */
    private function containerTags(array $container): array
    {
        $id = (string) ($container['Id'] ?? '');
        $names = $container['Names'] ?? [];
        $name = (\is_array($names) && isset($names[0])) ? ltrim((string) $names[0], '/') : 'unknown';
        $labels = \is_array($container['Labels'] ?? null) ? $container['Labels'] : [];

        $tags = [
            'container_id' => '' !== $id ? $id : substr($id, 0, 12),
            'container_name' => $name,
            'image' => mb_substr((string) ($container['Image'] ?? ''), 0, 255),
            'state' => (string) ($container['State'] ?? 'unknown'),
            'status' => (string) ($container['Status'] ?? 'unknown'),
        ];

        foreach (['compose_project' => 'com.docker.compose.project', 'compose_service' => 'com.docker.compose.service'] as $tag => $label) {
            $value = $labels[$label] ?? null;
            if (\is_string($value) && '' !== $value) {
                $tags[$tag] = mb_substr($value, 0, 255);
            }
        }

        return $tags;
    }

    /** @param array<string, mixed> $stats */
    private function cpuPercent(array $stats): ?float
    {
        $cpuStats = $stats['cpu_stats'] ?? null;
        $preCpuStats = $stats['precpu_stats'] ?? null;
        if (!\is_array($cpuStats) || !\is_array($preCpuStats)) {
            return null;
        }

        $cpuUsage = $cpuStats['cpu_usage'] ?? null;
        $preCpuUsage = $preCpuStats['cpu_usage'] ?? null;
        if (!\is_array($cpuUsage) || !\is_array($preCpuUsage)) {
            return null;
        }

        $cpuDelta = (float) ($cpuUsage['total_usage'] ?? 0) - (float) ($preCpuUsage['total_usage'] ?? 0);
        $systemDelta = (float) ($cpuStats['system_cpu_usage'] ?? 0) - (float) ($preCpuStats['system_cpu_usage'] ?? 0);
        if ($systemDelta <= 0.0 || $cpuDelta < 0.0) {
            return null;
        }

        $onlineCpus = $cpuStats['online_cpus'] ?? null;
        if (!\is_int($onlineCpus) && !\is_float($onlineCpus)) {
            $perCpu = $cpuUsage['percpu_usage'] ?? null;
            $onlineCpus = \is_array($perCpu) && [] !== $perCpu ? \count($perCpu) : 1;
        }

        return ($cpuDelta / $systemDelta) * (float) $onlineCpus * 100.0;
    }

    /** @param array<string, mixed> $stats */
    private function memoryUsedBytes(array $stats): float
    {
        $memory = $stats['memory_stats'] ?? null;
        if (!\is_array($memory)) {
            return 0.0;
        }

        $usage = (float) ($memory['usage'] ?? 0);
        if ($usage <= 0.0) {
            return 0.0;
        }

        // Match docker stats / moby calculateMemUsageUnixNoCache:
        // cgroup v1 → total_inactive_file; cgroup v2 → inactive_file.
        // Do not subtract the legacy "cache" field (over-subtracts on cgroup v2).
        $inner = $memory['stats'] ?? null;
        if (\is_array($inner)) {
            foreach (['total_inactive_file', 'inactive_file'] as $key) {
                if (!isset($inner[$key])) {
                    continue;
                }
                $reclaimable = (float) $inner[$key];
                if ($reclaimable > 0.0 && $reclaimable < $usage) {
                    return $usage - $reclaimable;
                }
            }
        }

        return $usage;
    }

    /** @param array<string, mixed> $stats */
    private function memoryLimitBytes(array $stats, float $hostMemoryTotal): float
    {
        $memory = $stats['memory_stats'] ?? null;
        if (!\is_array($memory)) {
            return $hostMemoryTotal;
        }

        $limit = (float) ($memory['limit'] ?? 0);
        if ($limit <= 0.0 || ($hostMemoryTotal > 0.0 && $limit > $hostMemoryTotal)) {
            return $hostMemoryTotal > 0.0 ? $hostMemoryTotal : $limit;
        }

        return $limit;
    }
}
