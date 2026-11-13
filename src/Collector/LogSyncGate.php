<?php

declare(strict_types=1);

namespace App\Collector;

final class LogSyncGate
{
    /** @var array<string, int> */
    private array $lastSync = [];

    public function __construct(
        private readonly int $syncIntervalSeconds,
    ) {
    }

    public function shouldSync(string $containerId, ?\DateTimeImmutable $now = null): bool
    {
        if ($this->syncIntervalSeconds <= 0) {
            return true;
        }

        $now ??= new \DateTimeImmutable();
        $ts = $now->getTimestamp();
        $last = $this->lastSync[$containerId] ?? 0;
        if ($ts - $last < $this->syncIntervalSeconds) {
            return false;
        }

        $this->lastSync[$containerId] = $ts;

        return true;
    }
}
