<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Collector\Docker\DockerLogParser;
use PHPUnit\Framework\TestCase;

final class DockerLogParserTest extends TestCase
{
    public function testItParsesPlainTextWithTimestamps(): void
    {
        $payload = "2024-06-01T12:00:00.000000000Z hello\n"
            ."2024-06-01T12:00:01.000000000Z world\n";

        $lines = (new DockerLogParser())->parse($payload, 100);

        self::assertCount(2, $lines);
        self::assertSame('stdout', $lines[0]['stream']);
        self::assertSame('hello', $lines[0]['line']);
        self::assertSame('world', $lines[1]['line']);
        self::assertInstanceOf(\DateTimeImmutable::class, $lines[0]['recordedAt']);
    }

    public function testItParsesMultiplexedFramesAndRespectsTail(): void
    {
        $stdout = "2024-06-01T12:00:00Z one\n";
        $stderr = "2024-06-01T12:00:01Z two\n";
        $payload = $this->frame(1, $stdout).$this->frame(2, $stderr);

        $lines = (new DockerLogParser())->parse($payload, 1);

        self::assertCount(1, $lines);
        self::assertSame('stderr', $lines[0]['stream']);
        self::assertSame('two', $lines[0]['line']);
    }

    public function testItTruncatesVeryLongLines(): void
    {
        $long = str_repeat('x', 5000);
        $lines = (new DockerLogParser())->parse($long, 100);

        self::assertCount(1, $lines);
        self::assertStringEndsWith('…', $lines[0]['line']);
        self::assertSame(4000, \strlen(substr($lines[0]['line'], 0, -\strlen('…'))));
    }

    private function frame(int $stream, string $chunk): string
    {
        return \chr($stream)."\0\0\0".pack('N', \strlen($chunk)).$chunk;
    }
}
