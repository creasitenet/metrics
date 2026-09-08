<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\MetricType;

final readonly class MetricPoint
{
    /** @param array<string, scalar|null> $tags */
    public function __construct(
        public string $metric,
        public MetricType $type,
        public float $value,
        public \DateTimeImmutable $timestamp,
        public array $tags = [],
    ) {
    }
}
