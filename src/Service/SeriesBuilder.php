<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Container;
use App\Entity\ContainerSample;
use App\Entity\HostSample;

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
    public const CONTAINER_RUNNING = 'docker.container.running';

    /**
     * @param list<Container>                $containers
     * @param array<string, ContainerSample> $latestSamples keyed by container id
     * @param list<HostSample>               $hostHistory
     *
     * @return array{host: array<string, array{labels: list<string>, values: list<float>, latest: float, count: int}>, containers: array<string, array<string, mixed>>}
     */
    public function buildDashboard(array $containers, array $latestSamples, array $hostHistory): array
    {
        $host = $this->hostSeries($hostHistory);
        $rows = [];

        foreach ($containers as $container) {
            $id = $container->getId();
            $sample = $latestSamples[$id] ?? null;
            $rows[$id] = [
                'id' => $id,
                'name' => $container->getName(),
                'image' => $container->getImage(),
                'state' => $container->getState(),
                'status' => $container->getStatus(),
                'compose_project' => $container->getComposeProject(),
                'compose_service' => $container->getComposeService(),
                'compose_working_dir' => $container->getComposeWorkingDir(),
                'compose_config_files' => $container->getComposeConfigFiles(),
                'group' => $container->getGroup(),
                'running' => $sample?->isRunning() ?? false,
                'cpu_percent' => $sample?->getCpuPercent(),
                'memory_used_bytes' => $sample?->getMemoryUsedBytes(),
                'memory_limit_bytes' => $sample?->getMemoryLimitBytes(),
                'metrics' => [],
            ];
        }

        ksort($rows);

        return ['host' => $host, 'containers' => $rows];
    }

    /**
     * Per-container series, used by the container detail page.
     *
     * @param list<ContainerSample> $samples
     *
     * @return array<string, array{labels: list<string>, values: list<float>, latest: float, count: int}>
     */
    public function buildContainerSeries(array $samples): array
    {
        $series = [];

        foreach ($samples as $sample) {
            $label = $sample->getRecordedAt()->format(\DATE_ATOM);

            $this->append($series, self::CONTAINER_CPU_PERCENT, $label, $sample->getCpuPercent());
            $this->append($series, self::CONTAINER_MEMORY_USED, $label, $sample->getMemoryUsedBytes());
            $this->append($series, self::CONTAINER_MEMORY_LIMIT, $label, $sample->getMemoryLimitBytes());
            $this->append($series, self::CONTAINER_RUNNING, $label, $sample->isRunning() ? 1.0 : 0.0);
        }

        return $series;
    }

    /**
     * @param list<HostSample> $history
     *
     * @return array<string, array{labels: list<string>, values: list<float>, latest: float, count: int}>
     */
    private function hostSeries(array $history): array
    {
        $series = [];

        foreach ($history as $sample) {
            $label = $sample->getRecordedAt()->format(\DATE_ATOM);

            $this->append($series, self::HOST_CPU_PERCENT, $label, $sample->getCpuPercent());
            $this->append($series, self::HOST_MEMORY_USED, $label, $sample->getMemoryUsedBytes());
            $this->append($series, self::HOST_MEMORY_TOTAL, $label, $sample->getMemoryTotalBytes());
            $this->append($series, self::HOST_CPU_COUNT, $label, $sample->getCpuCount());
            $this->append($series, self::HOST_IMAGES_TOTAL, $label, $sample->getImagesTotal());
            $this->append($series, self::HOST_GOROUTINES, $label, $sample->getGoroutines());
            $this->append($series, self::HOST_FDS_OPEN, $label, $sample->getFdsOpen());
            $this->append($series, self::CONTAINERS_TOTAL, $label, $sample->getContainersTotal());
            $this->append($series, self::CONTAINERS_RUNNING, $label, $sample->getContainersRunning());
            $this->append($series, self::CONTAINERS_STOPPED, $label, $sample->getContainersStopped());
        }

        return $series;
    }

    /**
     * @param array<string, array{labels: list<string>, values: list<float>, latest: float, count: int}> $series
     */
    private function append(array &$series, string $name, string $label, int|float|null $value): void
    {
        if (null === $value) {
            return;
        }

        $series[$name] ??= ['labels' => [], 'values' => [], 'latest' => 0.0, 'count' => 0];
        $series[$name]['labels'][] = $label;
        $series[$name]['values'][] = (float) $value;
        $series[$name]['latest'] = (float) $value;
        ++$series[$name]['count'];
    }
}
