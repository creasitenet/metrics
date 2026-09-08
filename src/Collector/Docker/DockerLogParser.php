<?php

declare(strict_types=1);

namespace App\Collector\Docker;

/** @phpstan-type LogLine array{stream: string, line: string, recordedAt: ?\DateTimeImmutable} */
final class DockerLogParser
{
    private const MAX_LINE_BYTES = 4000;

    /** @return list<LogLine> */
    public function parse(string $payload, int $tail = 100): array
    {
        if ('' === $payload) {
            return [];
        }

        $frames = $this->looksMultiplexed($payload)
            ? $this->demux($payload)
            : [['stream' => 'stdout', 'chunk' => $payload]];

        $lines = [];
        foreach ($frames as $frame) {
            foreach ($this->splitLines($frame['chunk']) as $rawLine) {
                if ('' === $rawLine) {
                    continue;
                }
                $lines[] = $this->parseLine($frame['stream'], $rawLine);
            }
        }

        if ($tail > 0 && \count($lines) > $tail) {
            return \array_slice($lines, -$tail);
        }

        return $lines;
    }

    private function looksMultiplexed(string $payload): bool
    {
        if (\strlen($payload) < 8) {
            return false;
        }

        $stream = \ord($payload[0]);
        if (!\in_array($stream, [0, 1, 2], true)) {
            return false;
        }

        if ("\0\0\0" !== substr($payload, 1, 3)) {
            return false;
        }

        $size = unpack('N', substr($payload, 4, 4));
        if (false === $size) {
            return false;
        }

        return $size[1] >= 0 && $size[1] <= \strlen($payload) - 8;
    }

    /** @return list<array{stream: string, chunk: string}> */
    private function demux(string $payload): array
    {
        $frames = [];
        $offset = 0;
        $length = \strlen($payload);

        while ($offset + 8 <= $length) {
            $streamCode = \ord($payload[$offset]);
            $sizeData = unpack('N', substr($payload, $offset + 4, 4));
            if (false === $sizeData) {
                break;
            }
            $size = $sizeData[1];
            $offset += 8;
            if ($size < 0 || $offset + $size > $length) {
                break;
            }

            $frames[] = [
                'stream' => 2 === $streamCode ? 'stderr' : 'stdout',
                'chunk' => substr($payload, $offset, $size),
            ];
            $offset += $size;
        }

        return $frames;
    }

    /** @return list<string> */
    private function splitLines(string $chunk): array
    {
        $chunk = str_replace(["\r\n", "\r"], "\n", $chunk);

        return explode("\n", $chunk);
    }

    /** @return LogLine */
    private function parseLine(string $stream, string $rawLine): array
    {
        $recordedAt = null;
        $line = $rawLine;

        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))\s(.*)$/s', $rawLine, $matches)) {
            try {
                $recordedAt = new \DateTimeImmutable($matches[1]);
            } catch (\Exception) {
                $recordedAt = null;
            }
            $line = $matches[2];
        }

        if (\strlen($line) > self::MAX_LINE_BYTES) {
            $line = substr($line, 0, self::MAX_LINE_BYTES).'…';
        }

        return [
            'stream' => $stream,
            'line' => $line,
            'recordedAt' => $recordedAt,
        ];
    }
}
