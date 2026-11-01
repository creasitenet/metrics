<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ContainerSampleRepository;
use App\Repository\HostSampleRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:sample:prune', description: 'Remove samples older than the configured retention period.')]
final class PruneSampleCommand extends Command
{
    public function __construct(
        private readonly ContainerSampleRepository $containerSamples,
        private readonly HostSampleRepository $hostSamples,
        #[Autowire('%app.metrics.retention_days%')]
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Retention period in days (defaults to METRICS_RETENTION_DAYS)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawDays = $input->getOption('days');
        $days = null === $rawDays || '' === $rawDays
            ? $this->retentionDays
            : filter_var($rawDays, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (false === $days || $days < 1) {
            $output->writeln('<error>--days must be a positive integer.</error>');

            return Command::INVALID;
        }

        $before = new \DateTimeImmutable(\sprintf('-%d days', $days));
        $containerRows = $this->containerSamples->pruneBefore($before);
        $hostRows = $this->hostSamples->pruneBefore($before);

        $output->writeln(\sprintf(
            '<info>%d container sample(s) and %d host sample(s) removed (older than %d day(s)).</info>',
            $containerRows,
            $hostRows,
            $days,
        ));

        return Command::SUCCESS;
    }
}
