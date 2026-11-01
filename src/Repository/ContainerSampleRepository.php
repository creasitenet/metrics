<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContainerSample;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContainerSample> */
final class ContainerSampleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContainerSample::class);
    }

    /**
     * Latest samples per container, restricted to the given ids.
     *
     * @param list<string> $containerIds
     *
     * @return array<string, ContainerSample> keyed by container id
     */
    public function latestForContainers(array $containerIds): array
    {
        if ([] === $containerIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.containerId IN (:ids)')
            ->andWhere('s.recordedAt = (SELECT MAX(s2.recordedAt) FROM App\Entity\ContainerSample s2 WHERE s2.containerId = s.containerId)')
            ->setParameter('ids', $containerIds)
            ->orderBy('s.recordedAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($rows as $row) {
            $id = $row->getContainerId();
            if (!isset($latest[$id])) {
                $latest[$id] = $row;
            }
        }

        return $latest;
    }

    /**
     * @return list<ContainerSample>
     */
    public function findForContainerSince(string $containerId, \DateTimeImmutable $since, int $limit = 5000): array
    {
        /** @var list<ContainerSample> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.containerId = :id OR s.containerId LIKE :prefix')
            ->andWhere('s.recordedAt >= :since')
            ->setParameter('id', $containerId)
            ->setParameter('prefix', $containerId.'%')
            ->setParameter('since', $since)
            ->orderBy('s.recordedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($rows);
    }

    /**
     * @return list<ContainerSample>
     */
    public function findSince(\DateTimeImmutable $since, int $limit = 20000): array
    {
        /** @var list<ContainerSample> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.recordedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('s.recordedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($rows);
    }

    public function pruneBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.recordedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /**
     * @param list<string> $keepContainerIds
     */
    public function deleteOrphanedByContainerIds(array $keepContainerIds): int
    {
        $qb = $this->createQueryBuilder('s')->delete();

        if ([] !== $keepContainerIds) {
            $qb->andWhere('s.containerId NOT IN (:ids)')
                ->setParameter('ids', $keepContainerIds);
        }

        return (int) $qb->getQuery()->execute();
    }
}
