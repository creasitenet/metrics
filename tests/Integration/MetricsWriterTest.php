<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dto\ContainerSample;
use App\Dto\ContainerSnapshot;
use App\Dto\DockerCollection;
use App\Dto\HostSample;
use App\Entity\Container;
use App\Entity\HostState;
use App\Entity\MetricBucket;
use App\Metrics\MetricsWriter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MetricsWriterTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MetricsWriter $writer;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->writer = $container->get(MetricsWriter::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testWritePersistsHostStateBucketsAndContainerGauges(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T10:00:00+00:00');

        $written = $this->writer->write(new DockerCollection(
            ['abc-123' => new ContainerSnapshot('abc-123', 'api', 'api:1', 'running', 'Up 2 minutes', 'shop', 'api')],
            ['abc-123' => new ContainerSample('abc-123', $now, true, 12.5, 1024, 4096)],
            new HostSample($now, 30.0, 2048, 8192, 4, 3, 2, 1, 10, 170, 166),
        ));

        self::assertSame(2, $written);

        $containers = $this->entityManager->getRepository(Container::class)->findAll();
        self::assertCount(1, $containers);
        self::assertSame(12.5, $containers[0]->getLastCpuPercent());

        $hostState = $this->entityManager->getRepository(HostState::class)->find(HostState::SINGLETON_ID);
        self::assertNotNull($hostState);
        self::assertSame(30.0, $hostState->getCpuPercent());

        $buckets = $this->entityManager->getRepository(MetricBucket::class)->findAll();
        self::assertGreaterThanOrEqual(2, \count($buckets));
    }

    public function testWriteUpdatesContainerMetadataInsteadOfDuplicating(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T10:00:00+00:00');

        $this->writer->write(new DockerCollection(
            ['abc-123' => new ContainerSnapshot('abc-123', 'api', 'api:1', 'running', 'Up 2 minutes', 'shop', 'api')],
            ['abc-123' => new ContainerSample('abc-123', $now, true, 12.5, 1024, 4096)],
            null,
        ));

        $this->entityManager->clear();

        $this->writer->write(new DockerCollection(
            ['abc-123' => new ContainerSnapshot('abc-123', 'api', 'api:2', 'running', 'Up 5 minutes', 'shop', 'api', '/srv/shop', '/srv/shop/compose.yaml', 'storefront')],
            ['abc-123' => new ContainerSample('abc-123', $now->modify('+30 seconds'), true, 20.0, 2048, 4096)],
            null,
        ));

        $this->entityManager->clear();

        $containers = $this->entityManager->getRepository(Container::class)->findAll();
        self::assertCount(1, $containers);
        self::assertSame('api:2', $containers[0]->getImage());
        self::assertSame(20.0, $containers[0]->getLastCpuPercent());
    }

    public function testWriteUpdatesStoppedContainerAndIgnoresUnknownStoppedContainers(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T10:00:00+00:00');
        $knownId = 'abc-123';
        $unknownStoppedId = 'def-456';

        $this->writer->write(new DockerCollection(
            [$knownId => new ContainerSnapshot($knownId, 'api', 'api:1', 'running', 'Up 2 minutes', 'shop', 'api')],
            [$knownId => new ContainerSample($knownId, $now, true, 12.5, 1024, 4096)],
            null,
        ));

        $this->entityManager->clear();

        $written = $this->writer->write(new DockerCollection(
            [
                $knownId => new ContainerSnapshot($knownId, 'api', 'api:1', 'exited', 'Exited (0) 1 minute ago', 'shop', 'api'),
                $unknownStoppedId => new ContainerSnapshot($unknownStoppedId, 'old', 'old:1', 'exited', 'Exited (0) 2 days ago', 'shop', 'old'),
            ],
            [
                $knownId => new ContainerSample($knownId, $now->modify('+30 seconds'), false),
                $unknownStoppedId => new ContainerSample($unknownStoppedId, $now->modify('+30 seconds'), false),
            ],
            null,
        ));

        self::assertSame(1, $written);
        $this->entityManager->clear();

        $containers = $this->entityManager->getRepository(Container::class)->findAll();
        self::assertCount(1, $containers);
        self::assertSame($knownId, $containers[0]->getId());
        self::assertSame('exited', $containers[0]->getState());
        self::assertFalse($containers[0]->isLastRunning());
        self::assertNull($containers[0]->getLastCpuPercent());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
