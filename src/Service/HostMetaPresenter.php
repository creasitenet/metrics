<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\HostState;

final class HostMetaPresenter
{
    /**
     * @param array<string, array{labels: list<string>, values: list<float>, latest: float, count: int, bucket_hint?: string}> $hostSeries
     *
     * @return array<string, mixed>|null
     */
    public function present(?HostState $state, array $hostSeries = []): ?array
    {
        if (null === $state) {
            return null;
        }

        $version = $state->getServerVersion();
        if (null !== $version && '' !== $version && !str_starts_with($version, 'v')) {
            $version = 'v'.$version;
        }

        $cpuValues = $hostSeries[SeriesBuilder::HOST_CPU_PERCENT]['values'] ?? [];
        $memValues = $hostSeries[SeriesBuilder::HOST_MEMORY_USED]['values'] ?? [];

        return [
            'hostname' => $state->getNodeName() ?? 'Docker host',
            'version' => $version,
            'containers' => $state->getContainersTotal(),
            'uptime' => DurationFormatter::humanShort($state->getUptimeSeconds()),
            'cpu_percent' => $state->getCpuPercent(),
            'cpu_count' => $state->getCpuCount(),
            'memory_used_bytes' => $state->getMemoryUsedBytes(),
            'memory_total_bytes' => $state->getMemoryTotalBytes(),
            'cpu_pulse' => $this->pulseBars($cpuValues),
            'mem_pulse' => $this->pulseBars($memValues),
        ];
    }

    /**
     * @param list<float> $values
     *
     * @return list<int>
     */
    private function pulseBars(array $values): array
    {
        if ([] === $values) {
            return [];
        }

        $slice = \array_slice($values, -16);
        $peak = max(max($slice), 1.0);
        $bars = [];
        foreach ($slice as $value) {
            $bars[] = (int) max(3, round(($value / $peak) * 100));
        }

        return $bars;
    }
}
