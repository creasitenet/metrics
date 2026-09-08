<?php

declare(strict_types=1);

namespace App\Twig;

use App\Log\LogLevelDetector;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class AppExtension extends AbstractExtension
{
    public function __construct(
        private LogLevelDetector $logLevelDetector,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('strip_ansi', $this->stripAnsi(...)),
            new TwigFilter('log_level', $this->logLevel(...)),
            new TwigFilter('format_bytes', $this->formatBytes(...)),
        ];
    }

    public function stripAnsi(string $value): string
    {
        $cleaned = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $value);

        return \is_string($cleaned) ? $cleaned : $value;
    }

    public function logLevel(string $value): string
    {
        return $this->logLevelDetector->detect($this->stripAnsi($value)) ?? '';
    }

    public function formatBytes(float|int|null $bytes, int $precision = 1): string
    {
        if (null === $bytes || $bytes < 0) {
            return '—';
        }

        $bytes = (float) $bytes;
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, $precision, '.', ',').' GiB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, $precision, '.', ',').' MiB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, $precision, '.', ',').' KiB';
        }

        return number_format($bytes, 0, '.', ',').' B';
    }
}
