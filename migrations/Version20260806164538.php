<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline schema (SQLite).
 */
final class Version20260806164538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create metric, data_point, data_state with indexed container_id.';
    }

    public function up(Schema $schema): void
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
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('data_point');
        $schema->dropTable('data_state');
        $schema->dropTable('metric');
    }
}
