<?php

declare(strict_types=1);

namespace App\Command;

use App\Metrics\MetricsRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:sample:prune', description: 'Prune rolled-up metrics, legacy samples, and old container logs per METRICS_* retention env.')]
final class PruneSampleCommand extends Command
{
    public function __construct(
        private readonly MetricsRetentionService $retention,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pruned = $this->retention->pruneAll();
        $output->writeln(\sprintf(
            '<info>Pruned raw %d, 5m %d, 1h %d bucket(s); legacy %d container / %d host sample(s); %d log row(s).</info>',
            $pruned['raw'],
            $pruned['rollup_5m'],
            $pruned['rollup_1h'],
            $pruned['legacy_container'],
            $pruned['legacy_host'],
            $pruned['logs'],
        ));

        return Command::SUCCESS;
    }
}
