<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\DurationFormatter;
use PHPUnit\Framework\TestCase;

final class DurationFormatterTest extends TestCase
{
    public function testFormatsDays(): void
    {
        self::assertSame('4 days', DurationFormatter::humanShort(4 * 86400));
        self::assertSame('1 day', DurationFormatter::humanShort(86400));
    }

    public function testTooShortReturnsNull(): void
    {
        self::assertNull(DurationFormatter::humanShort(30));
    }
}
