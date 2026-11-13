<?php

declare(strict_types=1);

namespace App\Command;

use App\Collector\Docker\DockerCollector;
use App\Metrics\MetricsRetentionService;
use App\Metrics\OrphanContainerPruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:collector:loop', description: 'Collect Docker metrics on a fixed interval until the process is stopped.')]
final class CollectorLoopCommand extends Command
{
    private bool $running = true;

    private ?string $lastMaintenanceDay = null;

    public function __construct(
        private readonly DockerCollector $collector,
        private readonly MetricsRetentionService $retention,
        private readonly OrphanContainerPruner $pruner,
        #[Autowire('%app.collector.interval_seconds%')]
        private readonly int $intervalSeconds,
    ) {
        parent::__construct();
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        if (!\function_exists('pcntl_signal')) {
            return [];
        }

        return [\SIGTERM, \SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->running = false;

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(1, $this->intervalSeconds);
        $output->writeln(\sprintf('<info>Collector loop started (every %d second(s)).</info>', $interval));

        while ($this->running) {
            $startedAt = time();
            $this->collect($output);
            $this->runMaintenance($output);

            $elapsed = time() - $startedAt;
            sleep(max(1, $interval - $elapsed));
        }

        $output->writeln('<info>Collector loop stopped.</info>');

        return Command::SUCCESS;
    }

    private function collect(OutputInterface $output): void
    {
        try {
            $written = $this->collector->collect();
        } catch (\Throwable $e) {
            $output->writeln(\sprintf('<error>Collection failed: %s</error>', $e->getMessage()));

            return;
        }

        $output->writeln(\sprintf('Collected %d sample(s).', $written));
    }

    private function runMaintenance(OutputInterface $output): void
    {
        $day = (new \DateTimeImmutable())->format('Y-m-d');
        if ($day === $this->lastMaintenanceDay) {
            return;
        }

        $this->lastMaintenanceDay = $day;

        $pruned = $this->retention->pruneAll();
        $output->writeln(\sprintf(
            'Pruned metrics buckets (raw %d, 5m %d, 1h %d), legacy (%d container, %d host), %d log row(s).',
            $pruned['raw'],
            $pruned['rollup_5m'],
            $pruned['rollup_1h'],
            $pruned['legacy_container'],
            $pruned['legacy_host'],
            $pruned['logs'],
        ));

        try {
            $result = $this->pruner->prune();
        } catch (\Throwable $e) {
            $output->writeln(\sprintf('<error>Orphan prune failed: %s</error>', $e->getMessage()));

            return;
        }

        $output->writeln(\sprintf(
            'Pruned orphan data (kept %d live id(s)): %d container(s), %d legacy sample(s), %d bucket(s), %d log row(s).',
            $result['kept'],
            $result['containers'],
            $result['samples'],
            $result['buckets'],
            $result['logs'],
        ));
    }
}
