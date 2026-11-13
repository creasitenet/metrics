<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MetricBucket;
use App\Metrics\ChartPeriod;
use App\Metrics\MetricResolution;
use App\Repository\MetricBucketRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ChartSeriesProvider
{
    public function __construct(
        private readonly MetricBucketRepository $buckets,
        #[Autowire('%app.collector.interval_seconds%')]
        private readonly int $collectorInterval,
        #[Autowire('%app.metrics.chart_max_points%')]
        private readonly int $chartMaxPoints,
    ) {
    }

    /**
     * @return array<string, array{labels: list<string>, values: list<float>, latest: float, count: int, bucket_hint: string}>
     */
    public function hostGaugeSeries(): array
    {
        $rows = $this->loadBuckets(MetricBucket::HOST_CONTAINER_ID);

        return $this->toSeries(
            $rows,
            $this->resolutionHint(MetricResolution::SECONDS_30),
            SeriesBuilder::HOST_CPU_PERCENT,
            SeriesBuilder::HOST_MEMORY_USED,
        );
    }

    /**
     * @return array<string, array{labels: list<string>, values: list<float>, latest: float, count: int, bucket_hint: string}>
     */
    public function containerGaugeSeries(string $containerId): array
    {
        $rows = $this->loadBuckets($containerId);

        return $this->toSeries(
            $rows,
            $this->resolutionHint(MetricResolution::SECONDS_30),
            SeriesBuilder::CONTAINER_CPU_PERCENT,
            SeriesBuilder::CONTAINER_MEMORY_USED,
        );
    }

    /**
     * @return list<MetricBucket>
     */
    private function loadBuckets(string $containerId): array
    {
        $since = ChartPeriod::since();
        $rows = $this->buckets->findSeries($containerId, ChartPeriod::preferredResolution(), $since);

        return $this->downsample($rows, $this->chartMaxPoints);
    }

    /**
     * @param list<MetricBucket> $rows
     *
     * @return list<MetricBucket>
     */
    private function downsample(array $rows, int $maxPoints): array
    {
        $count = \count($rows);
        if ($count <= $maxPoints || $count < 2) {
            return $rows;
        }

        $step = (int) ceil($count / $maxPoints);
        $merged = [];
        for ($i = 0; $i < $count; $i += $step) {
            $chunk = \array_slice($rows, $i, $step);
            $merged[] = $this->mergeChunk($chunk);
        }

        return $merged;
    }

    /**
     * @param list<MetricBucket> $chunk
     */
    private function mergeChunk(array $chunk): MetricBucket
    {
        $first = $chunk[0];
        $cpuSum = 0.0;
        $samples = 0;
        $memMax = 0;
        foreach ($chunk as $row) {
            $cpuSum += $row->getCpuAvg() * $row->getSampleCount();
            $samples += $row->getSampleCount();
            $memMax = max($memMax, $row->getMemUsedMax());
        }
        $cpuAvg = $samples > 0 ? $cpuSum / $samples : 0.0;

        return new MetricBucket(
            $first->getContainerId(),
            $first->getResolution(),
            $first->getBucketStart(),
            $cpuAvg,
            $memMax,
            $samples,
        );
    }

    /**
     * @param list<MetricBucket> $rows
     *
     * @return array<string, array{labels: list<string>, values: list<float>, latest: float, count: int, bucket_hint: string}>
     */
    private function toSeries(array $rows, string $bucketHint, string $cpuKey, string $memKey): array
    {
        $cpu = ['labels' => [], 'values' => [], 'latest' => 0.0, 'count' => 0, 'bucket_hint' => $bucketHint];
        $mem = ['labels' => [], 'values' => [], 'latest' => 0.0, 'count' => 0, 'bucket_hint' => $bucketHint];

        foreach ($rows as $row) {
            $label = $row->getBucketStart()->format(\DATE_ATOM);
            $cpu['labels'][] = $label;
            $cpu['values'][] = $row->getCpuAvg();
            $cpu['latest'] = $row->getCpuAvg();
            ++$cpu['count'];

            $mem['labels'][] = $label;
            $mem['values'][] = (float) $row->getMemUsedMax();
            $mem['latest'] = (float) $row->getMemUsedMax();
            ++$mem['count'];
        }

        return [
            $cpuKey => $cpu,
            $memKey => $mem,
        ];
    }

    private function resolutionHint(string $resolution): string
    {
        return match ($resolution) {
            MetricResolution::SECONDS_30 => \sprintf('%ds buckets', max(1, $this->collectorInterval)),
            MetricResolution::MINUTES_5 => '5m buckets',
            MetricResolution::HOURS_1 => '1h buckets',
            default => $resolution,
        };
    }
}
