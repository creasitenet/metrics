<?php

declare(strict_types=1);

namespace App\Command;

use App\Collector\Docker\DockerCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:collector:run', description: 'Collect Docker host and container metrics once.')]
final class CollectorRunCommand extends Command
{
    public function __construct(private DockerCollector $collector)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $points = $this->collector->collect();
        $output->writeln(\sprintf('<info>Collected %d point(s).</info>', $points));

        return Command::SUCCESS;
    }
}
