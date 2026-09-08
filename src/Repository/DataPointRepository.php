<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DataPoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DataPoint> */
final class DataPointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataPoint::class);
    }

    /** @return list<array<string, mixed>> */
    public function findSince(\DateTimeImmutable $since, int $limit = 20000): array
    {
        $points = $this->createQueryBuilder('d')
            ->innerJoin('d.metric', 'm')
            ->addSelect('m')
            ->andWhere('d.recordedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('d.recordedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_reverse($points);
    }

    /** @return list<array<string, mixed>> */
    public function findForContainerSince(string $containerId, \DateTimeImmutable $since, int $limit = 5000): array
    {
        $points = $this->createQueryBuilder('d')
            ->innerJoin('d.metric', 'm')
            ->addSelect('m')
            ->andWhere('d.recordedAt >= :since')
            ->andWhere('d.containerId = :id OR d.containerId LIKE :prefix')
            ->setParameter('since', $since)
            ->setParameter('id', $containerId)
            ->setParameter('prefix', $containerId.'%')
            ->orderBy('d.recordedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_reverse($points);
    }

    public function pruneBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.recordedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /**
     * Delete historical points for containers not present in $keepContainerIds.
     * Host rows (containerId IS NULL) are never deleted.
     *
     * @param list<string> $keepContainerIds
     */
    public function deleteOrphanedByContainerIds(array $keepContainerIds): int
    {
        $qb = $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.containerId IS NOT NULL');

        if ([] !== $keepContainerIds) {
            $qb->andWhere('d.containerId NOT IN (:ids)')
                ->setParameter('ids', $keepContainerIds);
        }

        return (int) $qb->getQuery()->execute();
    }
}
