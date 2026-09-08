<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Dto\MetricPoint;
use App\Entity\DataPoint;
use App\Entity\DataState;
use App\Entity\Metric;
use App\Repository\DataStateRepository;
use App\Repository\MetricRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MetricsWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MetricRepository $metricRepository,
        private DataStateRepository $dataStateRepository,
    ) {
    }

    /** @param list<MetricPoint> $points */
    public function write(array $points): int
    {
        if ([] === $points) {
            return 0;
        }

        return $this->entityManager->wrapInTransaction(function () use ($points): int {
            $metrics = $this->metricRepository->indexedByName();
            /** @var array<string, DataState> $statesByKey */
            $statesByKey = [];
            $written = 0;

            foreach ($points as $point) {
                $metric = $metrics[$point->metric] ?? null;
                if (null === $metric) {
                    $metric = new Metric($point->metric, $point->type);
                    $metrics[$point->metric] = $metric;
                    $this->entityManager->persist($metric);
                }

                $containerId = isset($point->tags['container_id']) && \is_string($point->tags['container_id']) && '' !== $point->tags['container_id']
                    ? $point->tags['container_id']
                    : null;

                if ($point->type->isHistorical()) {
                    $this->entityManager->persist(new DataPoint(
                        $metric,
                        $point->value,
                        $point->tags,
                        $point->timestamp,
                        $containerId,
                    ));
                } else {
                    $this->upsertDataState($metric, $point, $containerId, $statesByKey);
                }

                ++$written;
            }

            $this->entityManager->flush();

            return $written;
        });
    }

    /** @param array<string, DataState> $statesByKey */
    private function upsertDataState(Metric $metric, MetricPoint $point, ?string $containerId, array &$statesByKey): void
    {
        $cacheKey = $point->metric."\0".($containerId ?? '');
        $dataState = $statesByKey[$cacheKey] ?? null;

        if (null === $dataState && null !== $metric->getId()) {
            $dataState = $this->dataStateRepository->findOneByMetricAndContainer($metric, $containerId);
        }

        if (null === $dataState) {
            $dataState = new DataState($metric, $point->value, $point->tags, $point->timestamp, $containerId);
            $this->entityManager->persist($dataState);
            $statesByKey[$cacheKey] = $dataState;

            return;
        }

        $dataState->setValue($point->value);
        $dataState->setUpdatedAt($point->timestamp);
        $dataState->setTags($point->tags);
        $dataState->setContainerId($containerId);
        $statesByKey[$cacheKey] = $dataState;
    }
}
