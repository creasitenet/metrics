<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Collector\Docker\DockerEngineClient;
use App\Repository\ContainerLogRepository;
use App\Repository\DataPointRepository;
use App\Repository\DataStateRepository;

final class OrphanContainerPruner
{
    public function __construct(
        private DockerEngineClient $docker,
        private DataStateRepository $dataStates,
        private DataPointRepository $dataPoints,
        private ContainerLogRepository $containerLogs,
    ) {
    }

    /**
     * Compare DB container IDs to Docker inventory (running + stopped) and delete orphans.
     * Host-level rows (null containerId) are never touched. Metric catalog rows stay.
     *
     * @return array{states: int, points: int, logs: int, kept: int}
     */
    public function prune(): array
    {
        $keepIds = $this->liveContainerIds();

        return [
            'states' => $this->dataStates->deleteOrphanedByContainerIds($keepIds),
            'points' => $this->dataPoints->deleteOrphanedByContainerIds($keepIds),
            'logs' => $this->containerLogs->deleteOrphanedByContainerIds($keepIds),
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
