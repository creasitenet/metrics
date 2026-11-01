<?php

declare(strict_types=1);

namespace App\Command;

use App\Metrics\OrphanContainerPruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:orphan-containers:prune',
    description: 'Remove container, sample and log rows for containers no longer known to Docker.',
)]
final class PruneOrphanContainersCommand extends Command
{
    public function __construct(
        private readonly OrphanContainerPruner $pruner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->pruner->prune();
        } catch (\Throwable $e) {
            $output->writeln(\sprintf('<error>Orphan prune failed: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln(\sprintf(
            '<info>Orphan prune done (kept %d live id(s)): %d container(s), %d sample(s), %d log row(s) removed.</info>',
            $result['kept'],
            $result['containers'],
            $result['samples'],
            $result['logs'],
        ));

        return Command::SUCCESS;
    }
}
