<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ContainerSample
{
    public function __construct(
        public string $containerId,
        public \DateTimeImmutable $recordedAt,
        public bool $running,
        public ?float $cpuPercent = null,
        public ?int $memoryUsedBytes = null,
        public ?int $memoryLimitBytes = null,
    ) {
    }
}
