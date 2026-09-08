<?php

declare(strict_types=1);

namespace App;

use App\Message\CollectMetricsMessage;
use App\Message\PruneDataPointsMessage;
use App\Message\PruneOrphanContainersMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        private bool $collectorScheduleEnabled,
        private int $collectorIntervalSeconds,
        private int $retentionDays,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        $schedule = (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);

        if ($this->collectorScheduleEnabled && $this->collectorIntervalSeconds > 0) {
            $schedule->add(RecurringMessage::every(
                \sprintf('%d seconds', $this->collectorIntervalSeconds),
                new CollectMetricsMessage(),
            ));
        }

        if ($this->retentionDays > 0) {
            $schedule->add(RecurringMessage::cron(
                '0 3 * * *',
                new PruneDataPointsMessage(),
            ));
        }

        // Needs Docker socket (same consumer as collector). Uses ?all=1 so stopped
        // containers are kept; only removed containers are GC'd.
        $schedule->add(RecurringMessage::cron(
            '5 3 * * *',
            new PruneOrphanContainersMessage(),
        ));

        return $schedule;
    }
}
