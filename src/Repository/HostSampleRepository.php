<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HostSample;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HostSample> */
final class HostSampleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HostSample::class);
    }

    public function findLatest(): ?HostSample
    {
        /** @var HostSample|null $sample */
        $sample = $this->createQueryBuilder('h')
            ->orderBy('h.recordedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $sample;
    }

    /**
     * @return list<HostSample>
     */
    public function findSince(\DateTimeImmutable $since, int $limit = 5000): array
    {
        /** @var list<HostSample> $rows */
        $rows = $this->createQueryBuilder('h')
            ->andWhere('h.recordedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('h.recordedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_reverse($rows);
    }

    public function pruneBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('h')
            ->delete()
            ->andWhere('h.recordedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
