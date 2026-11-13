<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add container.health_status from Docker HEALTHCHECK.';
    }

    public function up(Schema $schema): void
    {
        $container = $schema->getTable('container');
        $container->addColumn('health_status', Types::STRING, ['length' => 16, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('container')->dropColumn('health_status');
    }
}
