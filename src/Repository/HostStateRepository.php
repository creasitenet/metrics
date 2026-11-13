<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HostState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HostState> */
final class HostStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HostState::class);
    }

    public function singleton(): ?HostState
    {
        return $this->find(HostState::SINGLETON_ID);
    }
}
