<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bucketed chart storage, host_state snapshot, container latest gauges.';
    }

    public function up(Schema $schema): void
    {
        $hostState = $schema->createTable('host_state');
        $hostState->addColumn('id', Types::INTEGER);
        $hostState->addColumn('cpu_percent', Types::FLOAT);
        $hostState->addColumn('memory_used_bytes', Types::BIGINT, ['notnull' => false]);
        $hostState->addColumn('memory_total_bytes', Types::BIGINT, ['notnull' => false]);
        $hostState->addColumn('cpu_count', Types::INTEGER, ['notnull' => false]);
        $hostState->addColumn('containers_total', Types::INTEGER, ['notnull' => false]);
        $hostState->addColumn('containers_running', Types::INTEGER, ['notnull' => false]);
        $hostState->addColumn('containers_stopped', Types::INTEGER, ['notnull' => false]);
        $hostState->addColumn('images_total', Types::INTEGER, ['notnull' => false]);
        $hostState->addColumn('goroutines', Types::INTEGER, ['notnull' => false]);
        $hostState->addColumn('fds_open', Types::INTEGER, ['notnull' => false]);
        $hostState->addColumn('sampled_at', Types::DATETIMETZ_IMMUTABLE);
        $hostState->setPrimaryKey(['id']);

        $bucket = $schema->createTable('metric_bucket');
        $bucket->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $bucket->addColumn('container_id', Types::STRING, ['length' => 128, 'default' => '']);
        $bucket->addColumn('resolution', Types::STRING, ['length' => 4]);
        $bucket->addColumn('bucket_start', Types::DATETIMETZ_IMMUTABLE);
        $bucket->addColumn('cpu_avg', Types::FLOAT);
        $bucket->addColumn('mem_used_max', Types::BIGINT);
        $bucket->addColumn('sample_count', Types::INTEGER);
        $bucket->setPrimaryKey(['id']);
        $bucket->addUniqueIndex(['container_id', 'resolution', 'bucket_start'], 'uniq_metric_bucket_scope');
        $bucket->addIndex(['container_id', 'resolution', 'bucket_start'], 'idx_metric_bucket_lookup');

        $container = $schema->getTable('container');
        $container->addColumn('last_cpu_percent', Types::FLOAT, ['notnull' => false]);
        $container->addColumn('last_memory_used_bytes', Types::BIGINT, ['notnull' => false]);
        $container->addColumn('last_memory_limit_bytes', Types::BIGINT, ['notnull' => false]);
        $container->addColumn('last_running', Types::BOOLEAN, ['notnull' => false]);
        $container->addColumn('sampled_at', Types::DATETIMETZ_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('metric_bucket');
        $schema->dropTable('host_state');

        $container = $schema->getTable('container');
        $container->dropColumn('last_cpu_percent');
        $container->dropColumn('last_memory_used_bytes');
        $container->dropColumn('last_memory_limit_bytes');
        $container->dropColumn('last_running');
        $container->dropColumn('sampled_at');
    }
}
