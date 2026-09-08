<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DataState;
use App\Entity\Metric;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DataState> */
final class DataStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataState::class);
    }

    public function findOneByMetricAndContainer(Metric $metric, ?string $containerId): ?DataState
    {
        $qb = $this->createQueryBuilder('ds')
            ->andWhere('ds.metric = :metric')
            ->setParameter('metric', $metric);

        if (null === $containerId) {
            $qb->andWhere('ds.containerId IS NULL');
        } else {
            $qb->andWhere('ds.containerId = :containerId')
                ->setParameter('containerId', $containerId);
        }

        /** @var DataState|null $state */
        $state = $qb
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $state;
    }

    /** @return list<array<string, mixed>> */
    public function findLatestStates(): array
    {
        return $this->createQueryBuilder('ds')
            ->innerJoin('ds.metric', 'm')
            ->addSelect('m')
            ->getQuery()
            ->getArrayResult();
    }

    /** @return list<array<string, mixed>> */
    public function findForContainerLatestStates(string $containerId): array
    {
        return $this->createQueryBuilder('ds')
            ->innerJoin('ds.metric', 'm')
            ->addSelect('m')
            ->andWhere('ds.containerId = :id OR ds.containerId LIKE :prefix')
            ->setParameter('id', $containerId)
            ->setParameter('prefix', $containerId.'%')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Delete per-container states whose containerId is not in $keepContainerIds.
     * Host rows (containerId IS NULL) are never deleted.
     *
     * @param list<string> $keepContainerIds
     */
    public function deleteOrphanedByContainerIds(array $keepContainerIds): int
    {
        $qb = $this->createQueryBuilder('ds')
            ->delete()
            ->andWhere('ds.containerId IS NOT NULL');

        if ([] !== $keepContainerIds) {
            $qb->andWhere('ds.containerId NOT IN (:ids)')
                ->setParameter('ids', $keepContainerIds);
        }

        return (int) $qb->getQuery()->execute();
    }
}
