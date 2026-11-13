<?php

declare(strict_types=1);

namespace App\Collector\Docker;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class DockerEngineClient
{
    /** Docker one-shot stats waits for a second sample; keep batches bounded. */
    private const STATS_BATCH_SIZE = 16;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $socketPath,
    ) {
    }

    /** @return array<string, mixed> */
    public function info(): array
    {
        return $this->getJson('/info');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listContainers(bool $all = false): array
    {
        $path = $all ? '/containers/json?all=1' : '/containers/json';
        $payload = $this->decode($this->request($path));
        if (!\is_array($payload) || !array_is_list($payload)) {
            return [];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function containerStats(string $containerId): array
    {
        return $this->getJson('/containers/'.rawurlencode($containerId).'/stats?stream=0');
    }

    /**
     * Fetch one-shot stats concurrently. Docker waits for a second sample on
     * each request (~1-2 s), so sequential calls make a cycle grow linearly
     * with the number of containers.
     *
     * @param list<string> $containerIds
     *
     * @return array{stats: array<string, array<string, mixed>>, errors: array<string, string>}
     */
    public function containerStatsMany(array $containerIds): array
    {
        if ([] === $containerIds) {
            return ['stats' => [], 'errors' => []];
        }

        $this->assertSocketReadable();

        $stats = [];
        $errors = [];

        foreach (array_chunk($containerIds, self::STATS_BATCH_SIZE) as $chunk) {
            /** @var array<string, ResponseInterface> $responses */
            $responses = [];

            foreach ($chunk as $id) {
                $responses[$id] = $this->httpClient->request('GET', 'http://localhost/containers/'.rawurlencode($id).'/stats?stream=0', [
                    'timeout' => 8,
                    'bindto' => $this->socketPath,
                ]);
            }

            foreach ($responses as $id => $response) {
                try {
                    $status = $response->getStatusCode();
                    if ($status < 200 || $status >= 300) {
                        throw new \RuntimeException(\sprintf('Docker API stats returned HTTP %d.', $status));
                    }

                    $payload = $this->decode($response->getContent(false));
                    if (!\is_array($payload) || array_is_list($payload)) {
                        throw new \RuntimeException('Docker API stats returned an unexpected payload.');
                    }

                    $stats[$id] = $payload;
                } catch (\Throwable $e) {
                    $errors[$id] = $e->getMessage();
                }
            }
        }

        return ['stats' => $stats, 'errors' => $errors];
    }

    public function containerLogs(string $containerId, int $tail = 100): string
    {
        $tail = max(1, $tail);
        $path = \sprintf(
            '/containers/%s/logs?stdout=1&stderr=1&timestamps=1&tail=%d',
            rawurlencode($containerId),
            $tail,
        );

        return $this->request($path, 15);
    }

    /** @return array<string, mixed> */
    private function getJson(string $path): array
    {
        $payload = $this->decode($this->request($path));
        if (!\is_array($payload) || array_is_list($payload)) {
            throw new \RuntimeException(\sprintf('Docker API %s returned an unexpected payload.', $path));
        }

        return $payload;
    }

    private function assertSocketReadable(): void
    {
        if (!is_readable($this->socketPath)) {
            throw new \RuntimeException(\sprintf('Docker socket is not readable: %s', $this->socketPath));
        }
    }

    private function request(string $path, float $timeout = 8): string
    {
        $this->assertSocketReadable();

        $response = $this->httpClient->request('GET', 'http://localhost'.$path, [
            'timeout' => $timeout,
            'bindto' => $this->socketPath,
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(\sprintf('Docker API %s returned HTTP %d.', $path, $status));
        }

        return $response->getContent(false);
    }

    private function decode(string $content): mixed
    {
        try {
            return json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Docker API returned invalid JSON.', 0, $exception);
        }
    }
}
