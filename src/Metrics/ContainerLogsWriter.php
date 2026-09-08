<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Entity\ContainerLog;
use App\Repository\ContainerLogRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ContainerLogsWriter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContainerLogRepository $containerLogRepository,
    ) {
    }

    /**
     * @param list<array{stream: string, line: string, recordedAt: ?\DateTimeImmutable}> $lines
     */
    public function replace(string $containerId, array $lines): int
    {
        if ('' === $containerId) {
            return 0;
        }

        return $this->entityManager->wrapInTransaction(function () use ($containerId, $lines): int {
            $this->containerLogRepository->deleteByContainerId($containerId);

            foreach ($lines as $position => $entry) {
                $this->entityManager->persist(new ContainerLog(
                    $containerId,
                    (int) $position,
                    $entry['stream'],
                    $entry['line'],
                    $entry['recordedAt'],
                ));
            }

            $this->entityManager->flush();

            return \count($lines);
        });
    }
}
