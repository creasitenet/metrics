<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces the dynamic metric catalogue (metric / data_point / data_state)
 * with explicit tables: container, container_sample, host_sample.
 *
 * Legacy rows are copied in postUp() once the new tables exist, then the
 * legacy tables are dropped. down() rebuilds them from the new tables.
 */
final class Version20261025140000 extends AbstractMigration
{
    private const CONTAINER_GAUGES = [
        'docker.container.cpu_percent' => 'cpu_percent',
        'docker.container.memory_used_bytes' => 'memory_used_bytes',
    ];

    private const CONTAINER_STATES = [
        'docker.container.running' => 'running',
        'docker.container.memory_limit_bytes' => 'memory_limit_bytes',
    ];

    private const HOST_GAUGES = [
        'docker.host.cpu_percent' => 'cpu_percent',
        'docker.host.memory_used_bytes' => 'memory_used_bytes',
        'docker.host.goroutines' => 'goroutines',
        'docker.host.fds_open' => 'fds_open',
    ];

    private const HOST_STATES = [
        'docker.host.memory_total_bytes' => 'memory_total_bytes',
        'docker.host.cpu_count' => 'cpu_count',
        'docker.host.images_total' => 'images_total',
        'docker.containers.total' => 'containers_total',
        'docker.containers.running' => 'containers_running',
        'docker.containers.stopped' => 'containers_stopped',
    ];

    /** @var array{containers: list<array<string, mixed>>, container_samples: list<array<string, mixed>>, host_samples: list<array<string, mixed>>} */
    private array $snapshot = ['containers' => [], 'container_samples' => [], 'host_samples' => []];

    public function getDescription(): string
    {
        return 'Replace the metric catalogue with container, container_sample and host_sample tables.';
    }

    public function up(Schema $schema): void
    {
        $container = $schema->createTable('container');
        $container->addColumn('id', Types::STRING, ['length' => 128]);
        $container->addColumn('name', Types::STRING, ['length' => 255]);
        $container->addColumn('image', Types::STRING, ['length' => 255]);
        $container->addColumn('state', Types::STRING, ['length' => 32]);
        $container->addColumn('status', Types::STRING, ['length' => 255]);
        $container->addColumn('compose_project', Types::STRING, ['length' => 255, 'notnull' => false]);
        $container->addColumn('compose_service', Types::STRING, ['length' => 255, 'notnull' => false]);
        $container->addColumn('compose_working_dir', Types::STRING, ['length' => 512, 'notnull' => false]);
        $container->addColumn('compose_config_files', Types::STRING, ['length' => 512, 'notnull' => false]);
        $container->addColumn('dozzle_group', Types::STRING, ['length' => 255, 'notnull' => false]);
        $container->addColumn('updated_at', Types::DATETIMETZ_IMMUTABLE);
        $container->setPrimaryKey(['id']);
        $container->addIndex(['compose_project'], 'idx_container_compose_project');
        $container->addIndex(['compose_working_dir'], 'idx_container_working_dir');

        $containerSample = $schema->createTable('container_sample');
        $containerSample->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $containerSample->addColumn('container_id', Types::STRING, ['length' => 128]);
        $containerSample->addColumn('recorded_at', Types::DATETIMETZ_IMMUTABLE);
        $containerSample->addColumn('running', Types::BOOLEAN);
        $containerSample->addColumn('cpu_percent', Types::FLOAT, ['notnull' => false]);
        $containerSample->addColumn('memory_used_bytes', Types::BIGINT, ['notnull' => false]);
        $containerSample->addColumn('memory_limit_bytes', Types::BIGINT, ['notnull' => false]);
        $containerSample->setPrimaryKey(['id']);
        $containerSample->addIndex(['container_id', 'recorded_at'], 'idx_container_sample_container_recorded');
        $containerSample->addIndex(['recorded_at'], 'idx_container_sample_recorded');

        $hostSample = $schema->createTable('host_sample');
        $hostSample->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $hostSample->addColumn('recorded_at', Types::DATETIMETZ_IMMUTABLE);
        $hostSample->addColumn('cpu_percent', Types::FLOAT);
        $hostSample->addColumn('memory_used_bytes', Types::BIGINT, ['notnull' => false]);
        $hostSample->addColumn('memory_total_bytes', Types::BIGINT, ['notnull' => false]);
        $hostSample->addColumn('cpu_count', Types::INTEGER, ['notnull' => false]);
        $hostSample->addColumn('containers_total', Types::INTEGER, ['notnull' => false]);
        $hostSample->addColumn('containers_running', Types::INTEGER, ['notnull' => false]);
        $hostSample->addColumn('containers_stopped', Types::INTEGER, ['notnull' => false]);
        $hostSample->addColumn('images_total', Types::INTEGER, ['notnull' => false]);
        $hostSample->addColumn('goroutines', Types::INTEGER, ['notnull' => false]);
        $hostSample->addColumn('fds_open', Types::INTEGER, ['notnull' => false]);
        $hostSample->setPrimaryKey(['id']);
        $hostSample->addIndex(['recorded_at'], 'idx_host_sample_recorded');
    }

    public function postUp(Schema $schema): void
    {
        if ($this->sm->tableExists('data_point') && $this->sm->tableExists('metric')) {
            $this->backfillContainers();
            $this->backfillContainerSamples();
            $this->backfillHostSamples();
        }

        $this->dropTable('data_point');
        $this->dropTable('data_state');
        $this->dropTable('metric');
    }

    public function preDown(Schema $schema): void
    {
        $this->snapshot = [
            'containers' => $this->fetchAll('SELECT * FROM container'),
            'container_samples' => $this->fetchAll('SELECT * FROM container_sample'),
            'host_samples' => $this->fetchAll('SELECT * FROM host_sample'),
        ];
    }

    public function down(Schema $schema): void
    {
        $metric = $schema->createTable('metric');
        $metric->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $metric->addColumn('name', Types::STRING, ['length' => 128]);
        $metric->addColumn('type', Types::STRING, ['length' => 16]);
        $metric->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $metric->setPrimaryKey(['id']);
        $metric->addUniqueIndex(['name'], 'uniq_metric_name');

        $dataPoint = $schema->createTable('data_point');
        $dataPoint->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $dataPoint->addColumn('metric_id', Types::INTEGER);
        $dataPoint->addColumn('value', Types::FLOAT);
        $dataPoint->addColumn('tags', Types::JSON);
        $dataPoint->addColumn('recorded_at', Types::DATETIMETZ_IMMUTABLE);
        $dataPoint->addColumn('container_id', Types::STRING, ['length' => 128, 'notnull' => false]);
        $dataPoint->setPrimaryKey(['id']);
        $dataPoint->addIndex(['metric_id'], 'IDX_DATA_POINT_METRIC');
        $dataPoint->addIndex(['metric_id', 'recorded_at'], 'idx_data_point_metric_recorded');
        $dataPoint->addIndex(['recorded_at'], 'idx_data_point_recorded');
        $dataPoint->addIndex(['container_id', 'recorded_at'], 'idx_data_point_container_recorded');
        $dataPoint->addForeignKeyConstraint('metric', ['metric_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_DATA_POINT_METRIC');

        $dataState = $schema->createTable('data_state');
        $dataState->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $dataState->addColumn('metric_id', Types::INTEGER);
        $dataState->addColumn('value', Types::FLOAT);
        $dataState->addColumn('tags', Types::JSON);
        $dataState->addColumn('updated_at', Types::DATETIMETZ_IMMUTABLE);
        $dataState->addColumn('container_id', Types::STRING, ['length' => 128, 'notnull' => false]);
        $dataState->setPrimaryKey(['id']);
        $dataState->addIndex(['metric_id'], 'IDX_DATA_STATE_METRIC');
        $dataState->addIndex(['container_id', 'metric_id'], 'idx_data_state_container_metric');
        $dataState->addUniqueIndex(['metric_id', 'container_id'], 'uniq_data_state_metric_container');
        $dataState->addForeignKeyConstraint('metric', ['metric_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_DATA_STATE_METRIC');

        $schema->dropTable('container_sample');
        $schema->dropTable('host_sample');
        $schema->dropTable('container');
    }

    public function postDown(Schema $schema): void
    {
        $this->restoreLegacyTables();
    }

    private function backfillContainers(): void
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT container_id FROM data_point WHERE container_id IS NOT NULL
             UNION SELECT container_id FROM data_state WHERE container_id IS NOT NULL',
        );

        $fallbackUpdatedAt = new \DateTimeImmutable();

        foreach ($ids as $id) {
            $tags = [];
            $latest = null;

            $points = $this->connection->executeQuery(
                'SELECT tags, recorded_at FROM data_point WHERE container_id = ? ORDER BY recorded_at',
                [$id],
            );
            while ($row = $points->fetchAssociative()) {
                $this->mergeTags($tags, $this->decodeTags($row['tags']));
                $latest = $this->later($latest, (string) $row['recorded_at']);
            }

            $states = $this->connection->executeQuery(
                'SELECT tags, updated_at FROM data_state WHERE container_id = ? ORDER BY updated_at',
                [$id],
            );
            while ($row = $states->fetchAssociative()) {
                $this->mergeTags($tags, $this->decodeTags($row['tags']));
                $latest = $this->later($latest, (string) $row['updated_at']);
            }

            $this->connection->insert('container', [
                'id' => $id,
                'name' => $this->tagString($tags, 'container_name', $id),
                'image' => $this->tagString($tags, 'image', ''),
                'state' => $this->tagString($tags, 'state', 'unknown'),
                'status' => $this->tagString($tags, 'status', 'unknown'),
                'compose_project' => $this->tagString($tags, 'compose_project', ''),
                'compose_service' => $this->tagString($tags, 'compose_service', ''),
                'compose_working_dir' => null,
                'compose_config_files' => null,
                'dozzle_group' => null,
                'updated_at' => $this->parseDate($latest ?? $fallbackUpdatedAt->format('Y-m-d H:i:s')),
            ], ['updated_at' => Types::DATETIMETZ_IMMUTABLE]);
        }
    }

    private function backfillContainerSamples(): void
    {
        $runningByContainer = [];
        $limitByContainer = [];
        $states = $this->connection->executeQuery(
            'SELECT ds.container_id AS container_id, ds.updated_at AS updated_at, m.name AS metric, ds.value AS value
             FROM data_state ds JOIN metric m ON m.id = ds.metric_id
             WHERE ds.container_id IS NOT NULL
               AND m.name IN (?, ?)',
            ['docker.container.running', 'docker.container.memory_limit_bytes'],
        );
        while ($row = $states->fetchAssociative()) {
            $containerId = (string) $row['container_id'];
            if ('docker.container.running' === $row['metric']) {
                $runningByContainer[$containerId] = (float) $row['value'] > 0.0;
            } else {
                $limitByContainer[$containerId] = [
                    'recorded_at' => (string) $row['updated_at'],
                    'value' => (int) (float) $row['value'],
                ];
            }
        }

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT container_id FROM data_point WHERE container_id IS NOT NULL',
        );

        foreach ($ids as $containerId) {
            $samples = [];
            $rows = $this->connection->executeQuery(
                'SELECT dp.recorded_at AS recorded_at, m.name AS metric, dp.value AS value, dp.tags AS tags
                 FROM data_point dp JOIN metric m ON m.id = dp.metric_id
                 WHERE dp.container_id = ?
                   AND m.name IN (?, ?)
                 ORDER BY dp.recorded_at, dp.id',
                [$containerId, 'docker.container.cpu_percent', 'docker.container.memory_used_bytes'],
            );
            while ($row = $rows->fetchAssociative()) {
                $key = (string) $row['recorded_at'];
                if (!isset($samples[$key])) {
                    $samples[$key] = [
                        'recorded_at' => $key,
                        'cpu_percent' => null,
                        'memory_used_bytes' => null,
                        'tags' => $this->decodeTags($row['tags']),
                    ];
                }

                $value = (float) $row['value'];
                if ('docker.container.cpu_percent' === $row['metric']) {
                    $samples[$key]['cpu_percent'] = $value;
                } else {
                    $samples[$key]['memory_used_bytes'] = (int) $value;
                }
            }

            foreach ($samples as $sample) {
                $tags = $sample['tags'];
                $state = $this->tagString($tags, 'state', '');
                $running = '' !== $state
                    ? 'running' === $state
                    : ($runningByContainer[$containerId] ?? false);

                $limit = null;
                if (isset($limitByContainer[$containerId]) && $limitByContainer[$containerId]['recorded_at'] === $sample['recorded_at']) {
                    $limit = $limitByContainer[$containerId]['value'];
                }

                $this->connection->insert('container_sample', [
                    'container_id' => $containerId,
                    'recorded_at' => $this->parseDate($sample['recorded_at']),
                    'running' => $running,
                    'cpu_percent' => $sample['cpu_percent'],
                    'memory_used_bytes' => $sample['memory_used_bytes'],
                    'memory_limit_bytes' => $limit,
                ], [
                    'recorded_at' => Types::DATETIMETZ_IMMUTABLE,
                    'running' => Types::BOOLEAN,
                    'cpu_percent' => Types::FLOAT,
                    'memory_used_bytes' => Types::BIGINT,
                    'memory_limit_bytes' => Types::BIGINT,
                ]);
            }

            unset($samples);
        }
    }

    private function backfillHostSamples(): void
    {
        $gaugeNames = array_keys(self::HOST_GAUGES);
        $stateNames = array_keys(self::HOST_STATES);

        $samples = [];

        $gaugeRows = $this->connection->executeQuery(
            'SELECT dp.recorded_at AS recorded_at, m.name AS metric, dp.value AS value
             FROM data_point dp JOIN metric m ON m.id = dp.metric_id
             WHERE m.name IN ('.$this->quotedList($gaugeNames).')
             ORDER BY dp.recorded_at',
        );
        while ($row = $gaugeRows->fetchAssociative()) {
            $metric = (string) $row['metric'];
            $recordedAt = (string) $row['recorded_at'];
            $samples[$recordedAt] ??= $this->emptyHostSample($recordedAt);
            $samples[$recordedAt][self::HOST_GAUGES[$metric]] = (float) $row['value'];
        }

        $stateRows = $this->connection->executeQuery(
            'SELECT ds.updated_at AS updated_at, m.name AS metric, ds.value AS value
             FROM data_state ds JOIN metric m ON m.id = ds.metric_id
             WHERE m.name IN ('.$this->quotedList($stateNames).')
             ORDER BY ds.updated_at',
        );
        while ($row = $stateRows->fetchAssociative()) {
            $metric = (string) $row['metric'];
            $updatedAt = (string) $row['updated_at'];
            $samples[$updatedAt] ??= $this->emptyHostSample($updatedAt);
            $samples[$updatedAt][self::HOST_STATES[$metric]] = (int) (float) $row['value'];
        }

        $this->flushHostSamples($samples);
    }

    /**
     * @param array<string, array<string, mixed>> $samples
     */
    private function flushHostSamples(array $samples): void
    {
        foreach ($samples as $sample) {
            $this->connection->insert('host_sample', [
                'recorded_at' => $this->parseDate((string) $sample['recorded_at']),
                'cpu_percent' => (float) $sample['cpu_percent'],
                'memory_used_bytes' => $sample['memory_used_bytes'],
                'memory_total_bytes' => $sample['memory_total_bytes'],
                'cpu_count' => $sample['cpu_count'],
                'containers_total' => $sample['containers_total'],
                'containers_running' => $sample['containers_running'],
                'containers_stopped' => $sample['containers_stopped'],
                'images_total' => $sample['images_total'],
                'goroutines' => $sample['goroutines'],
                'fds_open' => $sample['fds_open'],
            ], [
                'recorded_at' => Types::DATETIMETZ_IMMUTABLE,
                'cpu_percent' => Types::FLOAT,
                'memory_used_bytes' => Types::BIGINT,
                'memory_total_bytes' => Types::BIGINT,
                'cpu_count' => Types::INTEGER,
                'containers_total' => Types::INTEGER,
                'containers_running' => Types::INTEGER,
                'containers_stopped' => Types::INTEGER,
                'images_total' => Types::INTEGER,
                'goroutines' => Types::INTEGER,
                'fds_open' => Types::INTEGER,
            ]);
        }
    }

    /** @param list<string> $values */
    private function quotedList(array $values): string
    {
        return implode(', ', array_map(fn (string $v): string => $this->connection->quote($v), $values));
    }

    private function restoreLegacyTables(): void
    {
        $createdAt = new \DateTimeImmutable();
        $metricIds = [];

        foreach (self::CONTAINER_GAUGES + self::HOST_GAUGES as $name => $column) {
            $metricIds[$name] = $this->createMetric($name, 'gauge', $createdAt);
        }
        foreach (self::CONTAINER_STATES + self::HOST_STATES as $name => $column) {
            $metricIds[$name] = $this->createMetric($name, 'state', $createdAt);
        }

        $tagsByContainer = [];
        foreach ($this->snapshot['containers'] as $container) {
            $id = (string) $container['id'];
            $tagsByContainer[$id] = [
                'container_id' => $id,
                'container_name' => (string) $container['name'],
                'image' => (string) $container['image'],
                'state' => (string) $container['state'],
                'status' => (string) $container['status'],
                'compose_project' => (string) ($container['compose_project'] ?? ''),
                'compose_service' => (string) ($container['compose_service'] ?? ''),
            ];
        }

        $latestSampleByContainer = [];
        foreach ($this->snapshot['container_samples'] as $sample) {
            $containerId = (string) $sample['container_id'];
            $recordedAt = (string) $sample['recorded_at'];

            if (null !== $sample['cpu_percent']) {
                $this->insertDataPoint(
                    $metricIds['docker.container.cpu_percent'],
                    (float) $sample['cpu_percent'],
                    $tagsByContainer[$containerId] ?? [],
                    $recordedAt,
                    $containerId,
                );
            }

            if (null !== $sample['memory_used_bytes']) {
                $this->insertDataPoint(
                    $metricIds['docker.container.memory_used_bytes'],
                    (float) $sample['memory_used_bytes'],
                    $tagsByContainer[$containerId] ?? [],
                    $recordedAt,
                    $containerId,
                );
            }

            if (!isset($latestSampleByContainer[$containerId]) || $recordedAt > $latestSampleByContainer[$containerId]['recorded_at']) {
                $latestSampleByContainer[$containerId] = $sample;
            }
        }

        foreach ($latestSampleByContainer as $containerId => $sample) {
            $tags = $tagsByContainer[$containerId] ?? [];

            $this->insertDataState(
                $metricIds['docker.container.running'],
                $sample['running'] ? 1.0 : 0.0,
                $tags,
                (string) $sample['recorded_at'],
                $containerId,
            );

            if (null !== $sample['memory_limit_bytes']) {
                $this->insertDataState(
                    $metricIds['docker.container.memory_limit_bytes'],
                    (float) $sample['memory_limit_bytes'],
                    $tags,
                    (string) $sample['recorded_at'],
                    $containerId,
                );
            }
        }

        $latestHostSample = null;
        foreach ($this->snapshot['host_samples'] as $sample) {
            $recordedAt = (string) $sample['recorded_at'];

            foreach (self::HOST_GAUGES as $metric => $column) {
                if (null === $sample[$column]) {
                    continue;
                }

                $this->insertDataPoint($metricIds[$metric], (float) $sample[$column], [], $recordedAt, null);
            }

            if (null === $latestHostSample || $recordedAt > (string) $latestHostSample['recorded_at']) {
                $latestHostSample = $sample;
            }
        }

        if (null !== $latestHostSample) {
            foreach (self::HOST_STATES as $metric => $column) {
                if (null === $latestHostSample[$column]) {
                    continue;
                }

                $this->insertDataState(
                    $metricIds[$metric],
                    (float) $latestHostSample[$column],
                    [],
                    (string) $latestHostSample['recorded_at'],
                    null,
                );
            }
        }
    }

    private function createMetric(string $name, string $type, \DateTimeImmutable $createdAt): int
    {
        $this->connection->insert('metric', [
            'name' => $name,
            'type' => $type,
            'created_at' => $createdAt,
        ], ['created_at' => Types::DATETIME_IMMUTABLE]);

        return (int) $this->connection->fetchOne('SELECT id FROM metric WHERE name = ?', [$name]);
    }

    /** @param array<string, mixed> $tags */
    private function insertDataPoint(int $metricId, float $value, array $tags, string $recordedAt, ?string $containerId): void
    {
        $this->connection->insert('data_point', [
            'metric_id' => $metricId,
            'value' => $value,
            'tags' => $tags,
            'recorded_at' => $this->parseDate($recordedAt),
            'container_id' => $containerId,
        ], [
            'value' => Types::FLOAT,
            'tags' => Types::JSON,
            'recorded_at' => Types::DATETIMETZ_IMMUTABLE,
        ]);
    }

    /** @param array<string, mixed> $tags */
    private function insertDataState(int $metricId, float $value, array $tags, string $updatedAt, ?string $containerId): void
    {
        $this->connection->insert('data_state', [
            'metric_id' => $metricId,
            'value' => $value,
            'tags' => $tags,
            'updated_at' => $this->parseDate($updatedAt),
            'container_id' => $containerId,
        ], [
            'value' => Types::FLOAT,
            'tags' => Types::JSON,
            'updated_at' => Types::DATETIMETZ_IMMUTABLE,
        ]);
    }

    /** @return array<string, mixed> */
    private function emptyHostSample(string $recordedAt): array
    {
        return [
            'recorded_at' => $recordedAt,
            'cpu_percent' => 0.0,
            'memory_used_bytes' => null,
            'memory_total_bytes' => null,
            'cpu_count' => null,
            'containers_total' => null,
            'containers_running' => null,
            'containers_stopped' => null,
            'images_total' => null,
            'goroutines' => null,
            'fds_open' => null,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $tags
     */
    private function mergeTags(array &$metadata, array $tags): void
    {
        if ([] === $tags) {
            return;
        }

        $metadata = array_merge($metadata, array_filter(
            $tags,
            static fn (mixed $value): bool => \is_string($value) && '' !== $value,
        ));
    }

    /** @param array<string, mixed> $tags */
    private function tagString(array $tags, string $key, string $default): string
    {
        $value = $tags[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : $default;
    }

    /** @return array<string, mixed> */
    private function decodeTags(mixed $raw): array
    {
        if (\is_array($raw)) {
            return $raw;
        }

        if (!\is_string($raw) || '' === $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function later(?string $current, string $candidate): string
    {
        if (null === $current) {
            return $candidate;
        }

        return $candidate > $current ? $candidate : $current;
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        foreach ([$this->platform->getDateTimeTzFormatString(), 'Y-m-d H:i:s', 'Y-m-d H:i:sO', 'Y-m-d H:i:sP'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if (false !== $parsed) {
                return $parsed;
            }
        }

        return new \DateTimeImmutable($value);
    }

    /** @return list<array<string, mixed>> */
    private function fetchAll(string $sql): array
    {
        $rows = $this->connection->fetchAllAssociative($sql);

        return \is_array($rows) ? $rows : [];
    }

    private function dropTable(string $table): void
    {
        if (!$this->sm->tableExists($table)) {
            return;
        }

        $this->connection->executeStatement('DROP TABLE '.$this->platform->quoteIdentifier($table));
    }
}
