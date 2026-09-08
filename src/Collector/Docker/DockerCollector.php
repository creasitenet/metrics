<?php

declare(strict_types=1);

namespace App\Collector\Docker;

use App\Metrics\ContainerLogsWriter;
use App\Metrics\MetricsWriter;
use Psr\Log\LoggerInterface;

final class DockerCollector
{
    public function __construct(
        private DockerEngineClient $client,
        private DockerMetricsMapper $mapper,
        private DockerLogParser $logParser,
        private MetricsWriter $metricsWriter,
        private ContainerLogsWriter $logsWriter,
        private LoggerInterface $logger,
        private int $logTail,
    ) {
    }

    public function collect(): int
    {
        $info = $this->client->info();
        $containers = $this->client->listContainers();
        $statsById = [];

        foreach ($containers as $container) {
            $id = (string) ($container['Id'] ?? '');
            if ('' === $id) {
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

            if ($this->logTail > 0) {
                $this->syncLogs($id);
            }
        }

        return $this->metricsWriter->write(
            $this->mapper->map($info, $containers, $statsById, new \DateTimeImmutable()),
        );
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
