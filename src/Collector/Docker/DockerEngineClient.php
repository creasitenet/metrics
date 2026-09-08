<?php

declare(strict_types=1);

namespace App\Collector\Docker;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DockerEngineClient
{
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

    private function request(string $path, float $timeout = 8): string
    {
        if (!is_readable($this->socketPath)) {
            throw new \RuntimeException(\sprintf('Docker socket is not readable: %s', $this->socketPath));
        }

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
