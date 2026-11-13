<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Repository\ContainerLogRepository;
use App\Repository\ContainerSampleRepository;
use App\Repository\HostSampleRepository;
use App\Repository\MetricBucketRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MetricsRetentionService
{
    public function __construct(
        private readonly MetricBucketRepository $buckets,
        private readonly ContainerSampleRepository $legacyContainerSamples,
        private readonly HostSampleRepository $legacyHostSamples,
        private readonly ContainerLogRepository $containerLogs,
        #[Autowire('%app.metrics.retention_days%')]
        private readonly int $retentionDays,
        #[Autowire('%app.metrics.raw_hours%')]
        private readonly int $rawHours,
        #[Autowire('%app.metrics.log_retention_days%')]
        private readonly int $logRetentionDays,
    ) {
    }

    /**
     * @return array{raw: int, rollup_5m: int, rollup_1h: int, legacy_container: int, legacy_host: int, logs: int}
     */
    public function pruneAll(): array
    {
        $now = new \DateTimeImmutable();

        $raw = $this->buckets->pruneResolutionBefore(
            MetricResolution::SECONDS_30,
            $now->modify(\sprintf('-%d hours', max(1, $this->rawHours))),
        );
        $rollup5m = $this->buckets->pruneResolutionBefore(MetricResolution::MINUTES_5, $now);
        $rollup1h = $this->buckets->pruneResolutionBefore(MetricResolution::HOURS_1, $now);

        $legacyContainer = 0;
        $legacyHost = 0;
        if ($this->retentionDays > 0) {
            $before = $now->modify(\sprintf('-%d days', $this->retentionDays));
            $legacyContainer = $this->legacyContainerSamples->pruneBefore($before);
            $legacyHost = $this->legacyHostSamples->pruneBefore($before);
        }

        $logs = 0;
        if ($this->logRetentionDays > 0) {
            $logBefore = $now->modify(\sprintf('-%d days', $this->logRetentionDays));
            $logs = $this->containerLogs->pruneBefore($logBefore);
        }

        return [
            'raw' => $raw,
            'rollup_5m' => $rollup5m,
            'rollup_1h' => $rollup1h,
            'legacy_container' => $legacyContainer,
            'legacy_host' => $legacyHost,
            'logs' => $logs,
        ];
    }
}
