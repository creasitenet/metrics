<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Entity\MetricBucket;
use App\Repository\MetricBucketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MetricBucketWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MetricBucketRepository $buckets,
        #[Autowire('%app.collector.interval_seconds%')]
        private int $collectorInterval,
    ) {
    }

    public function recordHost(?float $cpuPercent, ?int $memoryUsedBytes, \DateTimeImmutable $at): void
    {
        if (null === $cpuPercent && null === $memoryUsedBytes) {
            return;
        }

        $this->record(MetricBucket::HOST_CONTAINER_ID, $cpuPercent ?? 0.0, $memoryUsedBytes ?? 0, $at);
    }

    public function recordContainer(string $containerId, ?float $cpuPercent, ?int $memoryUsedBytes, \DateTimeImmutable $at): void
    {
        if ('' === $containerId || (null === $cpuPercent && null === $memoryUsedBytes)) {
            return;
        }

        $this->record($containerId, $cpuPercent ?? 0.0, $memoryUsedBytes ?? 0, $at);
    }

    private function record(string $containerId, float $cpu, int $memUsed, \DateTimeImmutable $at): void
    {
        foreach (MetricResolution::all() as $resolution) {
            $start = MetricResolution::bucketStart($at, $resolution, $this->collectorInterval);
            $bucket = $this->buckets->findBucket($containerId, $resolution, $start);

            if (null === $bucket) {
                $this->entityManager->persist(new MetricBucket(
                    $containerId,
                    $resolution,
                    $start,
                    $cpu,
                    $memUsed,
                ));
                continue;
            }

            $bucket->addSample($cpu, $memUsed);
        }
    }
}
