<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class HostSample
{
    public function __construct(
        public \DateTimeImmutable $recordedAt,
        public float $cpuPercent = 0.0,
        public ?int $memoryUsedBytes = null,
        public ?int $memoryTotalBytes = null,
        public ?int $cpuCount = null,
        public ?int $containersTotal = null,
        public ?int $containersRunning = null,
        public ?int $containersStopped = null,
        public ?int $imagesTotal = null,
        public ?int $goroutines = null,
        public ?int $fdsOpen = null,
        public ?string $nodeName = null,
        public ?string $serverVersion = null,
        public ?int $uptimeSeconds = null,
    ) {
    }
}
