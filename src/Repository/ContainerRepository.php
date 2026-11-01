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

    /** @return array<string, Container> keyed by container id */
    public function indexedById(): array
    {
        $indexed = [];
        foreach ($this->findAll() as $container) {
            $indexed[$container->getId()] = $container;
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
