<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Container;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Container> */
final class ContainerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Container::class);
    }

    /**
     * Load only rows for the given Docker container ids (typically running
     * containers). Avoids findAll() which pulls thousands of orphan rows into RAM.
     *
     * @param list<string> $ids
     *
     * @return array<string, Container> keyed by container id
     */
    public function findIndexedByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $indexed = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $qb = $this->createQueryBuilder('c')
                ->where('c.id IN (:ids)')
                ->setParameter('ids', $chunk);
            foreach ($qb->getQuery()->getResult() as $container) {
                \assert($container instanceof Container);
                $indexed[$container->getId()] = $container;
            }
        }

        return $indexed;
    }

    /**
     * Delete containers not present in $keepContainerIds.
     *
     * @param list<string> $keepContainerIds
     */
    public function deleteNotIn(array $keepContainerIds): int
    {
        $qb = $this->createQueryBuilder('c')->delete();

        if ([] !== $keepContainerIds) {
            $qb->andWhere('c.id NOT IN (:ids)')
                ->setParameter('ids', $keepContainerIds);
        }

        return (int) $qb->getQuery()->execute();
    }
}
