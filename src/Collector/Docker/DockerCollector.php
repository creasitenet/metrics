<?php

declare(strict_types=1);

namespace App\Collector\Docker;

use App\Collector\LogSyncGate;
use App\Metrics\ContainerLogsWriter;
use App\Metrics\MetricsWriter;
use App\Metrics\OrphanContainerPruner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class DockerCollector
{
    public function __construct(
        private DockerEngineClient $client,
        private DockerMetricsMapper $mapper,
        private DockerLogParser $logParser,
        private MetricsWriter $metricsWriter,
        private ContainerLogsWriter $logsWriter,
        private OrphanContainerPruner $pruner,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private LogSyncGate $logSyncGate,
        #[Autowire('%app.metrics.log_tail%')]
        private int $logTail,
    ) {
    }

    public function collect(): int
    {
        $info = $this->client->info();
        $containers = $this->client->listContainers(all: true);
        $statsById = [];

        foreach ($containers as $container) {
            $id = (string) ($container['Id'] ?? '');
            if ('' === $id || 'running' !== ($container['State'] ?? '')) {
                continue;
            }

            try {
                $statsById[$id] = $this->client->containerStats($id);
            } catch (\Throwable $e) {
                $this->logger->warning('Docker stats failed for {id}: {error}', [
                    'id' => substr($id, 0, 12),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($containers as $container) {
            $id = (string) ($container['Id'] ?? '');
            if ('' === $id || 'running' !== ($container['State'] ?? '') || $this->logTail <= 0) {
                continue;
            }

            if (!$this->logSyncGate->shouldSync($id)) {
                continue;
            }

            $this->syncLogs($id);
            // Each log sync persists many ContainerLog rows; clear UoW so the
            // subsequent MetricsWriter::flush() does not topologically sort them too.
            $this->entityManager->clear();
        }

        $written = $this->metricsWriter->write(
            $this->mapper->map($info, $containers, $statsById, new \DateTimeImmutable()),
        );

        $this->pruneOrphans($containers);

        return $written;
    }

    /**
     * Reconcile on every cycle so containers recreated by `docker compose up`
     * (new id, same name) do not linger as duplicate dashboard rows until the
     * next daily maintenance.
     *
     * @param list<array<string, mixed>> $containers
     */
    private function pruneOrphans(array $containers): void
    {
        $keepIds = [];
        foreach ($containers as $container) {
            $id = (string) ($container['Id'] ?? '');
            if ('' !== $id) {
                $keepIds[] = $id;
            }
        }

        try {
            $this->pruner->prune($keepIds);
        } catch (\Throwable $e) {
            $this->logger->warning('Orphan prune failed: {error}', ['error' => $e->getMessage()]);
        }
    }

    private function syncLogs(string $containerId): void
    {
        try {
            $payload = $this->client->containerLogs($containerId, $this->logTail);
            $lines = $this->logParser->parse($payload, $this->logTail);
            $this->logsWriter->replace($containerId, $lines);
        } catch (\Throwable $e) {
            $this->logger->warning('Docker logs failed for {id}: {error}', [
                'id' => substr($containerId, 0, 12),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
