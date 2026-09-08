<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add container_log table for bounded Docker log tails.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('container_log');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('container_id', Types::STRING, ['length' => 128]);
        $table->addColumn('position', Types::INTEGER);
        $table->addColumn('stream', Types::STRING, ['length' => 16]);
        $table->addColumn('line', Types::TEXT);
        $table->addColumn('recorded_at', Types::DATETIMETZ_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['container_id', 'position'], 'idx_container_log_container_position');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('container_log');
    }
}
