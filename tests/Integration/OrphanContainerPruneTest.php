<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Collector\Docker\DockerEngineClient;
use App\Dto\ContainerSample;
use App\Dto\ContainerSnapshot;
use App\Dto\DockerCollection;
use App\Dto\HostSample;
use App\Entity\Container;
use App\Entity\ContainerLog;
use App\Entity\ContainerSample as ContainerSampleEntity;
use App\Entity\HostSample as HostSampleEntity;
use App\Metrics\ContainerLogsWriter;
use App\Metrics\MetricsWriter;
use App\Metrics\OrphanContainerPruner;
use App\Repository\ContainerLogRepository;
use App\Repository\ContainerRepository;
use App\Repository\ContainerSampleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OrphanContainerPruneTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MetricsWriter $metricsWriter;
    private ContainerLogsWriter $logsWriter;
    private ContainerRepository $containers;
    private ContainerSampleRepository $samples;
    private ContainerLogRepository $containerLogs;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->metricsWriter = $container->get(MetricsWriter::class);
        $this->logsWriter = $container->get(ContainerLogsWriter::class);
        $this->containers = $container->get(ContainerRepository::class);
        $this->samples = $container->get(ContainerSampleRepository::class);
        $this->containerLogs = $container->get(ContainerLogRepository::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testPruneRemovesDataOfContainersUnknownToDocker(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $liveId = str_repeat('a', 64);
        $goneId = str_repeat('b', 64);

        $this->metricsWriter->write(new DockerCollection(
            [
                $liveId => new ContainerSnapshot($liveId, 'live', 'live:1', 'running', 'Up 2 minutes', 'shop', 'api'),
                $goneId => new ContainerSnapshot($goneId, 'gone', 'gone:1', 'running', 'Up 3 minutes', 'shop', 'worker'),
            ],
            [
                $liveId => new ContainerSample($liveId, $now, true, 10.0, 1024, 4096),
                $goneId => new ContainerSample($goneId, $now, true, 20.0, 2048, 4096),
            ],
            new HostSample($now, 30.0, 2048, 8192, 4, 3, 2, 1, 10, 170, 166),
        ));

        $this->logsWriter->replace($liveId, [
            ['stream' => 'stdout', 'line' => 'live', 'recordedAt' => $now],
        ]);
        $this->logsWriter->replace($goneId, [
            ['stream' => 'stdout', 'line' => 'gone', 'recordedAt' => $now],
        ]);

        $this->entityManager->clear();

        $result = $this->pruner([$liveId])->prune();

        self::assertSame(1, $result['kept']);
        self::assertSame(1, $result['containers']);
        self::assertSame(1, $result['samples']);
        self::assertSame(1, $result['logs']);

        $this->entityManager->clear();

        $remainingContainers = $this->entityManager->getRepository(Container::class)->findAll();
        self::assertCount(1, $remainingContainers);
        self::assertSame($liveId, $remainingContainers[0]->getId());

        $remainingSamples = $this->entityManager->getRepository(ContainerSampleEntity::class)->findAll();
        self::assertCount(1, $remainingSamples);
        self::assertSame($liveId, $remainingSamples[0]->getContainerId());

        $remainingLogs = $this->entityManager->getRepository(ContainerLog::class)->findAll();
        self::assertCount(1, $remainingLogs);
        self::assertSame($liveId, $remainingLogs[0]->getContainerId());
    }

    public function testPruneKeepsHostSamplesUntouched(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $goneId = str_repeat('c', 64);

        $this->metricsWriter->write(new DockerCollection(
            [$goneId => new ContainerSnapshot($goneId, 'gone', 'gone:1', 'running', 'Up 3 minutes')],
            [$goneId => new ContainerSample($goneId, $now, true, 20.0, 2048, 4096)],
            new HostSample($now, 30.0, 2048, 8192, 4, 3, 2, 1, 10, 170, 166),
        ));

        $this->entityManager->clear();

        $result = $this->pruner([])->prune();

        self::assertSame(0, $result['kept']);
        self::assertSame(1, $result['containers']);
        self::assertSame(1, $result['samples']);

        $this->entityManager->clear();

        self::assertCount(0, $this->entityManager->getRepository(Container::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(ContainerSampleEntity::class)->findAll());

        $hostSamples = $this->entityManager->getRepository(HostSampleEntity::class)->findAll();
        self::assertCount(1, $hostSamples);
        self::assertSame(30.0, $hostSamples[0]->getCpuPercent());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    /**
     * @param list<string> $liveContainerIds
     */
    private function pruner(array $liveContainerIds): OrphanContainerPruner
    {
        $payload = array_map(
            static fn (string $id): array => ['Id' => $id, 'Names' => ['/'.$id]],
            $liveContainerIds,
        );

        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode($payload, \JSON_THROW_ON_ERROR),
        ));

        return new OrphanContainerPruner(
            new DockerEngineClient($client, '/dev/null'),
            $this->containers,
            $this->samples,
            $this->containerLogs,
        );
    }
}
