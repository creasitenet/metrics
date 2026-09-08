<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Log\LogLevelDetector;
use App\Twig\AppExtension;
use PHPUnit\Framework\TestCase;

final class LogLevelDetectorTest extends TestCase
{
    private LogLevelDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new LogLevelDetector();
    }

    public function testItDetectsTextLevels(): void
    {
        self::assertSame('error', $this->detector->detect('[2024-01-01] ERROR Failed to connect'));
        self::assertSame('warn', $this->detector->detect('WARN disk almost full'));
        self::assertSame('info', $this->detector->detect('INFO started'));
        self::assertSame('debug', $this->detector->detect('DEBUG probe ok'));
        self::assertNull($this->detector->detect('plain message without level'));
    }

    public function testItDetectsJsonLevels(): void
    {
        self::assertSame('error', $this->detector->detect('{"level":"error","msg":"boom"}'));
        self::assertSame('info', $this->detector->detect('{"severity":"INFO","message":"hello"}'));
    }

    public function testTwigFilters(): void
    {
        $ext = new AppExtension($this->detector);

        self::assertSame('hello', $ext->stripAnsi("\x1B[31mhello\x1B[0m"));
        self::assertSame('error', $ext->logLevel('ERROR something'));
        self::assertSame('', $ext->logLevel('no level here'));
    }
}
