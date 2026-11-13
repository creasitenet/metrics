<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContainerLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContainerLog> */
final class ContainerLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContainerLog::class);
    }

    public function deleteByContainerId(string $containerId): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->andWhere('l.containerId = :containerId')
            ->setParameter('containerId', $containerId)
            ->getQuery()
            ->execute();
    }

    /**
     * @return list<ContainerLog>
     */
    public function findLatestForContainer(string $containerId, int $limit = 100): array
    {
        /** @var list<ContainerLog> $rows */
        $rows = $this->createQueryBuilder('l')
            ->andWhere('l.containerId = :id OR l.containerId LIKE :prefix')
            ->setParameter('id', $containerId)
            ->setParameter('prefix', $containerId.'%')
            ->orderBy('l.position', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Delete log rows for containers not present in $keepContainerIds.
     *
     * @param list<string> $keepContainerIds
     */
    public function deleteOrphanedByContainerIds(array $keepContainerIds): int
    {
        $qb = $this->createQueryBuilder('l')->delete();

        if ([] !== $keepContainerIds) {
            $qb->andWhere('l.containerId NOT IN (:ids)')
                ->setParameter('ids', $keepContainerIds);
        }

        return (int) $qb->getQuery()->execute();
    }

    public function pruneBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->andWhere('l.recordedAt IS NOT NULL')
            ->andWhere('l.recordedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
