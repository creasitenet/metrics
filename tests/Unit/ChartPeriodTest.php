<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Metrics\ChartPeriod;
use App\Metrics\MetricResolution;
use PHPUnit\Framework\TestCase;

final class ChartPeriodTest extends TestCase
{
    public function testOnlyOneHourWindow(): void
    {
        self::assertSame('1h', ChartPeriod::resolve('30d'));
        self::assertSame(MetricResolution::SECONDS_30, ChartPeriod::preferredResolution());
        self::assertCount(1, ChartPeriod::PERIODS);
    }
}
