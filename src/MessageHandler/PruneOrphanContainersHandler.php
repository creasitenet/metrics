<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PruneOrphanContainersMessage;
use App\Metrics\OrphanContainerPruner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PruneOrphanContainersHandler
{
    public function __construct(
        private OrphanContainerPruner $pruner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PruneOrphanContainersMessage $message): void
    {
        $result = $this->pruner->prune();

        $this->logger->info(
            'Pruned orphan container data (kept {kept} live id(s)): {states} state(s), {points} point(s), {logs} log row(s)',
            $result,
        );
    }
}
