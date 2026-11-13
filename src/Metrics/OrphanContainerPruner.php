<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Collector\Docker\DockerEngineClient;
use App\Repository\ContainerLogRepository;
use App\Repository\ContainerRepository;
use App\Repository\ContainerSampleRepository;
use App\Repository\MetricBucketRepository;

final class OrphanContainerPruner
{
    public function __construct(
        private DockerEngineClient $docker,
        private ContainerRepository $containers,
        private ContainerSampleRepository $samples,
        private ContainerLogRepository $containerLogs,
        private MetricBucketRepository $metricBuckets,
    ) {
    }

    /**
     * Compare DB container ids to the Docker inventory (running + stopped) and delete orphans.
     * Host samples are never touched.
     *
     * @return array{containers: int, samples: int, buckets: int, logs: int, kept: int}
     */
    public function prune(): array
    {
        $keepIds = $this->liveContainerIds();

        $samples = $this->samples->deleteOrphanedByContainerIds($keepIds);
        $buckets = $this->metricBuckets->deleteOrphanedContainers($keepIds);
        $logs = $this->containerLogs->deleteOrphanedByContainerIds($keepIds);
        $containers = $this->containers->deleteNotIn($keepIds);

        return [
            'containers' => $containers,
            'samples' => $samples,
            'buckets' => $buckets,
            'logs' => $logs,
            'kept' => \count($keepIds),
        ];
    }

    /** @return list<string> */
    private function liveContainerIds(): array
    {
        $ids = [];
        foreach ($this->docker->listContainers(all: true) as $container) {
            $id = (string) ($container['Id'] ?? '');
            if ('' !== $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
