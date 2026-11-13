<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Dto\DockerCollection;
use App\Entity\Container;
use App\Entity\HostState;
use App\Repository\ContainerRepository;
use App\Repository\HostStateRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MetricsWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContainerRepository $containerRepository,
        private HostStateRepository $hostStateRepository,
        private MetricBucketWriter $metricBuckets,
    ) {
    }

    public function write(DockerCollection $collection): int
    {
        return $this->entityManager->wrapInTransaction(function () use ($collection): int {
            $liveIds = array_keys($collection->containers);
            $containers = $this->containerRepository->findIndexedByIds($liveIds);
            $written = 0;

            foreach ($collection->containers as $id => $snapshot) {
                $container = $containers[$id] ?? null;
                if (null === $container) {
                    // Only track containers we have seen running; listing
                    // `docker ps -a` would otherwise import every old stopped
                    // container into the dashboard.
                    if ('running' !== $snapshot->state) {
                        continue;
                    }

                    $container = new Container(
                        $id,
                        $snapshot->name,
                        $snapshot->image,
                        $snapshot->state,
                        $snapshot->status,
                        $snapshot->composeProject,
                        $snapshot->composeService,
                        $snapshot->composeWorkingDir,
                        $snapshot->composeConfigFiles,
                        $snapshot->group,
                    );
                    $containers[$id] = $container;
                    $this->entityManager->persist($container);
                } else {
                    $container
                        ->setName($snapshot->name)
                        ->setImage($snapshot->image)
                        ->setState($snapshot->state)
                        ->setStatus($snapshot->status)
                        ->setComposeProject($snapshot->composeProject)
                        ->setComposeService($snapshot->composeService)
                        ->setComposeWorkingDir($snapshot->composeWorkingDir)
                        ->setComposeConfigFiles($snapshot->composeConfigFiles)
                        ->setGroup($snapshot->group)
                        ->setUpdatedAt(new \DateTimeImmutable());
                }

                $containers[$id]->setHealthStatus($snapshot->healthStatus);

                $sample = $collection->sampleFor($id);
                if (null !== $sample) {
                    $container->applyLatestSample(
                        $sample->running,
                        $sample->cpuPercent,
                        $sample->memoryUsedBytes,
                        $sample->memoryLimitBytes,
                        $sample->recordedAt,
                    );
                    $this->metricBuckets->recordContainer(
                        $id,
                        $sample->cpuPercent,
                        $sample->memoryUsedBytes,
                        $sample->recordedAt,
                    );
                    ++$written;
                }
            }

            $host = $collection->host;
            if (null !== $host) {
                $state = $this->hostStateRepository->singleton();
                if (null === $state) {
                    $state = new HostState(
                        $host->cpuPercent,
                        $host->memoryUsedBytes,
                        $host->memoryTotalBytes,
                        $host->cpuCount,
                        $host->containersTotal,
                        $host->containersRunning,
                        $host->containersStopped,
                        $host->imagesTotal,
                        $host->goroutines,
                        $host->fdsOpen,
                        $host->recordedAt,
                        $host->nodeName,
                        $host->serverVersion,
                        $host->uptimeSeconds,
                    );
                    $this->entityManager->persist($state);
                } else {
                    $state->refresh(
                        $host->cpuPercent,
                        $host->memoryUsedBytes,
                        $host->memoryTotalBytes,
                        $host->cpuCount,
                        $host->containersTotal,
                        $host->containersRunning,
                        $host->containersStopped,
                        $host->imagesTotal,
                        $host->goroutines,
                        $host->fdsOpen,
                        $host->recordedAt,
                        $host->nodeName,
                        $host->serverVersion,
                        $host->uptimeSeconds,
                    );
                }

                $this->metricBuckets->recordHost(
                    $host->cpuPercent,
                    $host->memoryUsedBytes,
                    $host->recordedAt,
                );
                ++$written;
            }

            $this->entityManager->flush();

            return $written;
        });
    }
}
