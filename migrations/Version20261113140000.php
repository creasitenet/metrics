<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store Docker node name and engine version on host_state.';
    }

    public function up(Schema $schema): void
    {
        $host = $schema->getTable('host_state');
        $host->addColumn('node_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $host->addColumn('server_version', Types::STRING, ['length' => 64, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('host_state');
        $table->dropColumn('node_name');
        $table->dropColumn('server_version');
    }
}
