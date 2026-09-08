<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Collector\Docker\DockerCollector;
use App\Message\CollectMetricsMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CollectMetricsHandler
{
    public function __construct(
        private DockerCollector $collector,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CollectMetricsMessage $message): void
    {
        $points = $this->collector->collect();
        $this->logger->info('Docker collector wrote {points} point(s)', ['points' => $points]);
    }
}
