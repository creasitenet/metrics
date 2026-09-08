<?php

declare(strict_types=1);

namespace App\Log;

/**
 * Best-effort log level detection for display coloring.
 * Does not try to understand every format — unknown stays neutral.
 */
final class LogLevelDetector
{
    /** @return 'error'|'warn'|'info'|'debug'|null */
    public function detect(string $line): ?string
    {
        $line = trim($line);
        if ('' === $line) {
            return null;
        }

        $fromJson = $this->fromJson($line);
        if (null !== $fromJson) {
            return $fromJson;
        }

        return $this->fromText($line);
    }

    /** @return 'error'|'warn'|'info'|'debug'|null */
    private function fromJson(string $line): ?string
    {
        if (!str_starts_with($line, '{')) {
            return null;
        }

        try {
            $data = json_decode($line, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data)) {
            return null;
        }

        foreach (['level', 'severity', 'log.level', 'loglevel', 'severityName'] as $key) {
            if (!isset($data[$key]) || (!\is_string($data[$key]) && !\is_int($data[$key]))) {
                continue;
            }
            $normalized = $this->normalize((string) $data[$key]);
            if (null !== $normalized) {
                return $normalized;
            }
        }

        return null;
    }

    /** @return 'error'|'warn'|'info'|'debug'|null */
    private function fromText(string $line): ?string
    {
        // Prefer explicit tokens near the start of the line (common in app logs).
        $head = substr($line, 0, 180);

        if (preg_match('/\b(ERROR|FATAL|CRITICAL|EMERGENCY|PANIC|ERR)\b/i', $head)) {
            return 'error';
        }
        if (preg_match('/\b(WARN(?:ING)?)\b/i', $head)) {
            return 'warn';
        }
        if (preg_match('/\b(INFO|NOTICE)\b/i', $head)) {
            return 'info';
        }
        if (preg_match('/\b(DEBUG|TRACE|VERBOSE)\b/i', $head)) {
            return 'debug';
        }

        return null;
    }

    /** @return 'error'|'warn'|'info'|'debug'|null */
    private function normalize(string $raw): ?string
    {
        $value = strtolower(trim($raw));

        return match ($value) {
            'error', 'err', 'fatal', 'critical', 'crit', 'emergency', 'panic', 'alert', 'severe' => 'error',
            'warn', 'warning' => 'warn',
            'info', 'information', 'notice' => 'info',
            'debug', 'trace', 'verbose' => 'debug',
            default => null,
        };
    }
}
