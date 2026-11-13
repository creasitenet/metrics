<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Collector\Docker\DockerEngineClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DockerEngineClientTest extends TestCase
{
    public function testContainerStatsManyIsolatesPerContainerErrors(): void
    {
        $client = new DockerEngineClient(new MockHttpClient([
            new MockResponse(json_encode(['memory_stats' => ['usage' => 1024]], \JSON_THROW_ON_ERROR)),
            new MockResponse('nope', ['http_code' => 500]),
        ]), '/dev/null');

        $result = $client->containerStatsMany(['aaaa', 'bbbb']);

        self::assertArrayHasKey('aaaa', $result['stats']);
        self::assertSame(['usage' => 1024], $result['stats']['aaaa']['memory_stats']);
        self::assertArrayHasKey('bbbb', $result['errors']);
        self::assertStringContainsString('HTTP 500', $result['errors']['bbbb']);
    }

    public function testContainerStatsManyReturnsEmptyResultForEmptyInput(): void
    {
        $client = new DockerEngineClient(new MockHttpClient([]), '/dev/null');

        self::assertSame(['stats' => [], 'errors' => []], $client->containerStatsMany([]));
    }
}
