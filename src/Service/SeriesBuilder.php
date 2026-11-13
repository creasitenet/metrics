<?php

declare(strict_types=1);

namespace App\Service;

use App\Collector\Docker\DockerContainerUptime;
use App\Entity\Container;
use App\Entity\HostState;

final class SeriesBuilder
{
    public const HOST_CPU_PERCENT = 'docker.host.cpu_percent';
    public const HOST_MEMORY_USED = 'docker.host.memory_used_bytes';
    public const HOST_MEMORY_TOTAL = 'docker.host.memory_total_bytes';
    public const HOST_CPU_COUNT = 'docker.host.cpu_count';
    public const HOST_IMAGES_TOTAL = 'docker.host.images_total';
    public const HOST_GOROUTINES = 'docker.host.goroutines';
    public const HOST_FDS_OPEN = 'docker.host.fds_open';
    public const CONTAINERS_TOTAL = 'docker.containers.total';
    public const CONTAINERS_RUNNING = 'docker.containers.running';
    public const CONTAINERS_STOPPED = 'docker.containers.stopped';
    public const CONTAINER_CPU_PERCENT = 'docker.container.cpu_percent';
    public const CONTAINER_MEMORY_USED = 'docker.container.memory_used_bytes';
    public const CONTAINER_MEMORY_LIMIT = 'docker.container.memory_limit_bytes';

    /**
     * @param list<Container>                                                                                                  $containers
     * @param array<string, array{labels: list<string>, values: list<float>, latest: float, count: int, bucket_hint?: string}> $hostChartSeries
     *
     * @return array{host: array<string, array{labels: list<string>, values: list<float>, latest: float, count: int, bucket_hint?: string}>, containers: array<string, array<string, mixed>>}
     */
    public function buildDashboard(?HostState $hostState, array $containers, array $hostChartSeries): array
    {
        $hostSeries = array_merge($this->hostInfoTiles($hostState), $hostChartSeries);
        if (null !== $hostState) {
            $hostSeries = $this->ensureHostLiveGauges($hostState, $hostSeries);
        }
        $rows = [];

        foreach ($containers as $container) {
            $id = $container->getId();
            $rows[$id] = [
                'id' => $id,
                'short_id' => substr($id, 0, 12),
                'name' => $container->getName(),
                'image' => $container->getImage(),
                'state' => $container->getState(),
                'status' => $container->getStatus(),
                'compose_project' => $container->getComposeProject(),
                'compose_service' => $container->getComposeService(),
                'compose_working_dir' => $container->getComposeWorkingDir(),
                'compose_config_files' => $container->getComposeConfigFiles(),
                'group' => $container->getGroup(),
                'running' => $container->isLastRunning() ?? false,
                'cpu_percent' => $container->getLastCpuPercent(),
                'memory_used_bytes' => $container->getLastMemoryUsedBytes(),
                'memory_limit_bytes' => $container->getLastMemoryLimitBytes(),
                'health_status' => $container->getHealthStatus(),
                'running_for' => DockerContainerUptime::fromDockerStatus($container->getStatus()),
            ];
        }

        ksort($rows);

        return ['host' => $hostSeries, 'containers' => $rows];
    }

    /**
     * @return array<string, array{labels: list<string>, values: list<float>, latest: float, count: int}>
     */
    private function hostInfoTiles(?HostState $host): array
    {
        if (null === $host) {
            return [];
        }

        $at = $host->getSampledAt()->format(\DATE_ATOM);
        $tiles = [];

        $this->setTile($tiles, self::HOST_CPU_COUNT, $at, $host->getCpuCount());
        $this->setTile($tiles, self::HOST_MEMORY_TOTAL, $at, $host->getMemoryTotalBytes());
        $this->setTile($tiles, self::HOST_IMAGES_TOTAL, $at, $host->getImagesTotal());
        $this->setTile($tiles, self::HOST_GOROUTINES, $at, $host->getGoroutines());
        $this->setTile($tiles, self::HOST_FDS_OPEN, $at, $host->getFdsOpen());
        $this->setTile($tiles, self::CONTAINERS_TOTAL, $at, $host->getContainersTotal());
        $this->setTile($tiles, self::CONTAINERS_RUNNING, $at, $host->getContainersRunning());
        $this->setTile($tiles, self::CONTAINERS_STOPPED, $at, $host->getContainersStopped());

        return $tiles;
    }

    /**
     * @param array<string, array{labels: list<string>, values: list<float>, latest: float, count: int, bucket_hint?: string}> $host
     *
     * @return array<string, array{labels: list<string>, values: list<float>, latest: float, count: int, bucket_hint?: string}>
     */
    private function ensureHostLiveGauges(HostState $hostState, array $host): array
    {
        $at = $hostState->getSampledAt()->format(\DATE_ATOM);

        if (!isset($host[self::HOST_CPU_PERCENT]) || 0 === $host[self::HOST_CPU_PERCENT]['count']) {
            $host[self::HOST_CPU_PERCENT] = [
                'labels' => [$at],
                'values' => [$hostState->getCpuPercent()],
                'latest' => $hostState->getCpuPercent(),
                'count' => 1,
            ];
        }

        $mem = $hostState->getMemoryUsedBytes();
        if (null !== $mem && (!isset($host[self::HOST_MEMORY_USED]) || 0 === $host[self::HOST_MEMORY_USED]['count'])) {
            $host[self::HOST_MEMORY_USED] = [
                'labels' => [$at],
                'values' => [(float) $mem],
                'latest' => (float) $mem,
                'count' => 1,
            ];
        }

        return $host;
    }

    /**
     * @param array<string, array{labels: list<string>, values: list<float>, latest: float, count: int}> $tiles
     */
    private function setTile(array &$tiles, string $name, string $label, int|float|null $value): void
    {
        if (null === $value) {
            return;
        }

        $tiles[$name] = [
            'labels' => [$label],
            'values' => [(float) $value],
            'latest' => (float) $value,
            'count' => 1,
        ];
    }
}
