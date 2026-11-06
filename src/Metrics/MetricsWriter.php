<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Dto\DockerCollection;
use App\Entity\Container;
use App\Entity\ContainerSample;
use App\Entity\HostSample;
use App\Repository\ContainerRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MetricsWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContainerRepository $containerRepository,
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

                $sample = $collection->sampleFor($id);
                if (null === $sample) {
                    continue;
                }

                $this->entityManager->persist(new ContainerSample(
                    $sample->containerId,
                    $sample->recordedAt,
                    $sample->running,
                    $sample->cpuPercent,
                    $sample->memoryUsedBytes,
                    $sample->memoryLimitBytes,
                ));
                ++$written;
            }

            $host = $collection->host;
            if (null !== $host) {
                $this->entityManager->persist(new HostSample(
                    $host->recordedAt,
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
                ));
                ++$written;
            }

            $this->entityManager->flush();

            return $written;
        });
    }
}
