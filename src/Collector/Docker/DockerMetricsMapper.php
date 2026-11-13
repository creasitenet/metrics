<?php

declare(strict_types=1);

namespace App\Collector\Docker;

use App\Dto\ContainerSample;
use App\Dto\ContainerSnapshot;
use App\Dto\DockerCollection;
use App\Dto\HostSample;

final class DockerMetricsMapper
{
    private const COMPOSE_LABELS = [
        'com.docker.compose.project' => 'composeProject',
        'com.docker.compose.service' => 'composeService',
        'com.docker.compose.project.working_dir' => 'composeWorkingDir',
        'com.docker.compose.project.config_files' => 'composeConfigFiles',
        'dev.dozzle.group' => 'group',
    ];

    /**
     * @param array<string, mixed>                $info
     * @param list<array<string, mixed>>          $containers
     * @param array<string, array<string, mixed>> $statsById
     */
    public function map(array $info, array $containers, array $statsById, \DateTimeImmutable $now): DockerCollection
    {
        $total = (int) ($info['Containers'] ?? \count($containers));
        $running = (int) ($info['ContainersRunning'] ?? 0);
        $paused = (int) ($info['ContainersPaused'] ?? 0);
        $stopped = (int) ($info['ContainersStopped'] ?? max(0, $total - $running - $paused));
        $hostNcpu = (int) ($info['NCPU'] ?? 0);
        $hostMemoryTotal = (int) ($info['MemTotal'] ?? 0);
        $hostMemoryUsed = 0;
        $hostCpuSum = 0.0;
        $hostCpuSamples = 0;

        $snapshots = [];
        $samples = [];

        foreach ($containers as $container) {
            $id = (string) ($container['Id'] ?? '');
            if ('' === $id) {
                continue;
            }

            $snapshots[$id] = $this->containerSnapshot($container);

            if (!isset($statsById[$id])) {
                continue;
            }

            $isRunning = 'running' === ($container['State'] ?? '');
            if (!$isRunning) {
                $samples[$id] = new ContainerSample($id, $now, false);
                continue;
            }

            $stats = $statsById[$id];
            $cpu = $this->cpuPercent($stats);
            $memoryUsed = $this->memoryUsedBytes($stats);
            $memoryLimit = $this->memoryLimitBytes($stats, $hostMemoryTotal);

            $hostMemoryUsed += $memoryUsed;
            if (null !== $cpu) {
                $hostCpuSum += $cpu;
                ++$hostCpuSamples;
            }

            $samples[$id] = new ContainerSample($id, $now, true, $cpu, $memoryUsed, $memoryLimit);
        }

        $nodeName = $info['Name'] ?? null;
        $serverVersion = $info['ServerVersion'] ?? null;

        $host = new HostSample(
            $now,
            $hostCpuSamples > 0 ? $hostCpuSum : 0.0,
            $hostMemoryUsed,
            $hostMemoryTotal > 0 ? $hostMemoryTotal : null,
            $hostNcpu > 0 ? $hostNcpu : null,
            $total,
            $running,
            $stopped,
            isset($info['Images']) ? (int) $info['Images'] : null,
            isset($info['NGoroutines']) ? (int) $info['NGoroutines'] : null,
            isset($info['NFd']) ? (int) $info['NFd'] : null,
            \is_string($nodeName) && '' !== $nodeName ? mb_substr($nodeName, 0, 255) : null,
            \is_string($serverVersion) && '' !== $serverVersion ? mb_substr($serverVersion, 0, 64) : null,
            HostUptime::seconds(),
        );

        return new DockerCollection($snapshots, $samples, $host);
    }

    /**
     * @param array<string, mixed> $container
     */
    private function containerSnapshot(array $container): ContainerSnapshot
    {
        $id = (string) ($container['Id'] ?? '');
        $names = $container['Names'] ?? [];
        $name = (\is_array($names) && isset($names[0])) ? ltrim((string) $names[0], '/') : 'unknown';
        $labels = \is_array($container['Labels'] ?? null) ? $container['Labels'] : [];

        $compose = [];
        foreach (self::COMPOSE_LABELS as $label => $property) {
            $value = $labels[$label] ?? null;
            if (\is_string($value) && '' !== $value) {
                $compose[$property] = mb_substr($value, 0, 512);
            }
        }

        $status = (string) ($container['Status'] ?? 'unknown');

        return new ContainerSnapshot(
            $id,
            $name,
            mb_substr((string) ($container['Image'] ?? ''), 0, 255),
            (string) ($container['State'] ?? 'unknown'),
            $status,
            $compose['composeProject'] ?? null,
            $compose['composeService'] ?? null,
            $compose['composeWorkingDir'] ?? null,
            $compose['composeConfigFiles'] ?? null,
            $compose['group'] ?? null,
            DockerHealthStatus::fromDockerStatus($status),
        );
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
    private function memoryUsedBytes(array $stats): int
    {
        $memory = $stats['memory_stats'] ?? null;
        if (!\is_array($memory)) {
            return 0;
        }

        $usage = (float) ($memory['usage'] ?? 0);
        if ($usage <= 0.0) {
            return 0;
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
                    return (int) ($usage - $reclaimable);
                }
            }
        }

        return (int) $usage;
    }

    /** @param array<string, mixed> $stats */
    private function memoryLimitBytes(array $stats, int $hostMemoryTotal): int
    {
        $memory = $stats['memory_stats'] ?? null;
        if (!\is_array($memory)) {
            return $hostMemoryTotal;
        }

        $limit = (int) ($memory['limit'] ?? 0);
        if ($limit <= 0 || ($hostMemoryTotal > 0 && $limit > $hostMemoryTotal)) {
            return $hostMemoryTotal > 0 ? $hostMemoryTotal : $limit;
        }

        return $limit;
    }
}
