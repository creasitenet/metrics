<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Collector\Docker\DockerCollector;
use App\Collector\Docker\DockerEngineClient;
use App\Collector\Docker\DockerLogParser;
use App\Collector\Docker\DockerMetricsMapper;
use App\Collector\LogSyncGate;
use App\Dto\ContainerSample;
use App\Dto\ContainerSnapshot;
use App\Dto\DockerCollection;
use App\Entity\Container;
use App\Metrics\ContainerLogsWriter;
use App\Metrics\MetricsWriter;
use App\Metrics\OrphanContainerPruner;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DockerCollectorTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MetricsWriter $metricsWriter;
    private ContainerLogsWriter $logsWriter;
    private OrphanContainerPruner $pruner;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->metricsWriter = $container->get(MetricsWriter::class);
        $this->logsWriter = $container->get(ContainerLogsWriter::class);
        $this->pruner = $container->get(OrphanContainerPruner::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testCollectRemovesContainerReplacedByComposeAndSkipsUnknownStoppedContainers(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $oldId = str_repeat('a', 64);
        $newId = str_repeat('b', 64);
        $oldStoppedId = str_repeat('c', 64);

        $this->metricsWriter->write(new DockerCollection(
            [$oldId => new ContainerSnapshot($oldId, 'shop-api-1', 'shop-api:1', 'running', 'Up 2 minutes', 'shop', 'api')],
            [$oldId => new ContainerSample($oldId, $now, true, 12.5, 1024, 4096)],
            null,
        ));
        $this->entityManager->clear();

        $info = [
            'Containers' => 2,
            'ContainersRunning' => 1,
            'ContainersPaused' => 0,
            'ContainersStopped' => 1,
            'NCPU' => 2,
            'MemTotal' => 1_000_000_000,
            'Name' => 'test-host',
            'ServerVersion' => '27.0.0',
        ];
        $inventory = [
            [
                'Id' => $newId,
                'Names' => ['/shop-api-1'],
                'Image' => 'shop-api:2',
                'State' => 'running',
                'Status' => 'Up 5 seconds',
                'Labels' => [
                    'com.docker.compose.project' => 'shop',
                    'com.docker.compose.service' => 'api',
                ],
            ],
            [
                'Id' => $oldStoppedId,
                'Names' => ['/legacy-job'],
                'Image' => 'legacy:1',
                'State' => 'exited',
                'Status' => 'Exited (0) 2 days ago',
                'Labels' => [],
            ],
        ];
        $stats = ['memory_stats' => ['usage' => 2048, 'limit' => 4096]];

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($info, \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode($inventory, \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode($stats, \JSON_THROW_ON_ERROR)),
        ]);

        $collector = new DockerCollector(
            new DockerEngineClient($httpClient, '/dev/null'),
            new DockerMetricsMapper(),
            new DockerLogParser(),
            $this->metricsWriter,
            $this->logsWriter,
            $this->pruner,
            $this->entityManager,
            new NullLogger(),
            new LogSyncGate(300),
            0,
        );

        self::assertSame(2, $collector->collect());
        $this->entityManager->clear();

        $containers = $this->entityManager->getRepository(Container::class)->findAll();
        self::assertCount(1, $containers);
        self::assertSame($newId, $containers[0]->getId());
        self::assertSame('shop-api:2', $containers[0]->getImage());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
