<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MetricBucket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MetricBucket> */
final class MetricBucketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MetricBucket::class);
    }

    public function findBucket(string $containerId, string $resolution, \DateTimeImmutable $bucketStart): ?MetricBucket
    {
        return $this->findOneBy([
            'containerId' => $containerId,
            'resolution' => $resolution,
            'bucketStart' => $bucketStart,
        ]);
    }

    /**
     * @return list<MetricBucket>
     */
    public function findSeries(
        string $containerId,
        string $resolution,
        \DateTimeImmutable $since,
        ?\DateTimeImmutable $until = null,
    ): array {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.containerId = :containerId')
            ->andWhere('b.resolution = :resolution')
            ->andWhere('b.bucketStart >= :since')
            ->setParameter('containerId', $containerId)
            ->setParameter('resolution', $resolution)
            ->setParameter('since', $since)
            ->orderBy('b.bucketStart', 'ASC');

        if (null !== $until) {
            $qb->andWhere('b.bucketStart <= :until')->setParameter('until', $until);
        }

        /** @var list<MetricBucket> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    public function pruneResolutionBefore(string $resolution, \DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('b')
            ->delete()
            ->andWhere('b.resolution = :resolution')
            ->andWhere('b.bucketStart < :before')
            ->setParameter('resolution', $resolution)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /**
     * @param list<string> $keepContainerIds
     */
    public function deleteOrphanedContainers(array $keepContainerIds): int
    {
        $qb = $this->createQueryBuilder('b')->delete()
            ->andWhere('b.containerId != :host')
            ->setParameter('host', MetricBucket::HOST_CONTAINER_ID);

        if ([] !== $keepContainerIds) {
            $qb->andWhere('b.containerId NOT IN (:ids)')
                ->setParameter('ids', $keepContainerIds);
        }

        return (int) $qb->getQuery()->execute();
    }
}
