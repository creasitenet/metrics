<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:sqlite:vacuum', description: 'Reclaim SQLite disk space (metrics and collector should be stopped for a zero-copy vacuum).')]
final class SqliteVacuumCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $output->writeln('<comment>VACUUM is only supported for SQLite.</comment>');

            return Command::SUCCESS;
        }

        $this->connection->executeStatement('VACUUM');
        $output->writeln('<info>VACUUM completed.</info>');

        return Command::SUCCESS;
    }
}
