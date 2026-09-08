<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PruneDataPointsMessage;
use App\Repository\DataPointRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PruneDataPointsHandler
{
    public function __construct(
        private DataPointRepository $dataPoints,
        private LoggerInterface $logger,
        #[Autowire('%app.metrics.retention_days%')]
        private int $retentionDays,
    ) {
    }

    public function __invoke(PruneDataPointsMessage $message): void
    {
        $deleted = $this->dataPoints->pruneBefore(
            new \DateTimeImmutable(\sprintf('-%d days', $this->retentionDays)),
        );

        $this->logger->info('Pruned {deleted} data_point row(s) older than {days} day(s)', [
            'deleted' => $deleted,
            'days' => $this->retentionDays,
        ]);
    }
}
