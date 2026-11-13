<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add host_state.uptime_seconds from /proc/uptime when available.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('host_state')->addColumn('uptime_seconds', 'integer', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('host_state')->dropColumn('uptime_seconds');
    }
}
