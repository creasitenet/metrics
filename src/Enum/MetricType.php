<?php

declare(strict_types=1);

namespace App\Enum;

enum MetricType: string
{
    case GAUGE = 'gauge';
    case COUNTER = 'counter';
    case STATE = 'state';

    public function isHistorical(): bool
    {
        return match ($this) {
            self::GAUGE, self::COUNTER => true,
            self::STATE => false,
        };
    }
}
