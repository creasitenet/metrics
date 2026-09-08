<?php

declare(strict_types=1);

namespace App\Service;

final class SeriesBuilder
{
    private const CONTAINER_PREFIX = 'docker.container.';

    /**
     * @param list<array<string, mixed>> $states
     * @param list<array<string, mixed>> $points
     *
     * @return array{host: array<string, mixed>, containers: array<string, mixed>}
     */
    public function buildDashboard(array $states, array $points): array
    {
        $host = [];
        $containers = [];

        foreach ($states as $state) {
            $name = (string) ($state['metric']['name'] ?? '');
            $tags = \is_array($state['tags'] ?? null) ? $state['tags'] : [];
            $value = (float) ($state['value'] ?? 0);

            if ($this->isHostMetric($name)) {
                $host[$name] = [
                    'labels' => $host[$name]['labels'] ?? [],
                    'values' => $host[$name]['values'] ?? [],
                    'latest' => $value,
                    'count' => $host[$name]['count'] ?? 0,
                ];
                continue;
            }

            if (!str_starts_with($name, self::CONTAINER_PREFIX)) {
                continue;
            }

            $key = $this->containerKey($tags);
            $containers[$key] = $this->mergeContainer($tags, $containers[$key] ?? null);
            $containers[$key]['metrics'][$name] ??= ['labels' => [], 'values' => [], 'latest' => $value, 'count' => 0];
            $containers[$key]['metrics'][$name]['latest'] = $value;
        }

        foreach ($points as $point) {
            $name = (string) ($point['metric']['name'] ?? '');
            $tags = \is_array($point['tags'] ?? null) ? $point['tags'] : [];
            $recordedAt = $point['recordedAt'] ?? null;
            $entry = [
                'label' => $recordedAt instanceof \DateTimeImmutable ? $recordedAt->format(\DATE_ATOM) : '',
                'value' => (float) ($point['value'] ?? 0),
            ];

            if (str_starts_with($name, self::CONTAINER_PREFIX)) {
                $key = $this->containerKey($tags);
                $containers[$key] = $this->mergeContainer($tags, $containers[$key] ?? null);
                $this->append($containers[$key]['metrics'], $name, $entry);
                continue;
            }

            if ($this->isHostMetric($name)) {
                $this->append($host, $name, $entry);
            }
        }

        ksort($containers);

        return ['host' => $host, 'containers' => $containers];
    }

    private function isHostMetric(string $name): bool
    {
        return str_starts_with($name, 'docker.host.') || str_starts_with($name, 'docker.containers.');
    }

    /** @param array<string, scalar|null> $tags */
    private function containerKey(array $tags): string
    {
        $name = $this->tag($tags, 'container_name');
        if ('' !== $name) {
            return $name;
        }

        $id = $this->tag($tags, 'container_id');

        return '' !== $id ? $id : 'unknown';
    }

    /**
     * @param array<string, scalar|null> $tags
     * @param array<string, mixed>|null  $existing
     *
     * @return array<string, mixed>
     */
    private function mergeContainer(array $tags, ?array $existing): array
    {
        $row = $existing ?? [
            'name' => '',
            'image' => '',
            'container_id' => '',
            'compose_project' => '',
            'compose_service' => '',
            'state' => '',
            'status' => '',
            'health' => '',
            'ports' => '',
            'metrics' => [],
        ];

        $name = $this->tag($tags, 'container_name');
        $row['name'] = '' !== $name ? $name : ('' !== $row['name'] ? $row['name'] : $this->containerKey($tags));
        foreach (['image', 'container_id', 'compose_project', 'compose_service', 'state', 'status', 'health', 'ports'] as $field) {
            $value = $this->tag($tags, $field);
            if ('' !== $value) {
                $row[$field] = $value;
            }
        }

        return $row;
    }

    /** @param array<string, scalar|null> $tags */
    private function tag(array $tags, string $key): string
    {
        $value = $tags[$key] ?? null;

        return \is_string($value) || \is_int($value) || \is_float($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed>               $series
     * @param array{label: string, value: float} $entry
     */
    private function append(array &$series, string $name, array $entry): void
    {
        $series[$name] ??= ['labels' => [], 'values' => [], 'latest' => 0.0, 'count' => 0];
        $series[$name]['labels'][] = $entry['label'];
        $series[$name]['values'][] = $entry['value'];
        $series[$name]['latest'] = $entry['value'];
        ++$series[$name]['count'];
    }
}
