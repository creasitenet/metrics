<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dto\ContainerSample;
use App\Dto\ContainerSnapshot;
use App\Dto\DockerCollection;
use App\Dto\HostSample;
use App\Entity\Container;
use App\Entity\ContainerSample as ContainerSampleEntity;
use App\Entity\HostSample as HostSampleEntity;
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

    public function testWritePersistsContainerSampleAndHostSample(): void
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
        self::assertSame('abc-123', $containers[0]->getId());
        self::assertSame('api', $containers[0]->getName());
        self::assertSame('shop', $containers[0]->getComposeProject());
        self::assertSame('api', $containers[0]->getComposeService());

        $samples = $this->entityManager->getRepository(ContainerSampleEntity::class)->findAll();
        self::assertCount(1, $samples);
        self::assertSame('abc-123', $samples[0]->getContainerId());
        self::assertTrue($samples[0]->isRunning());
        self::assertSame(12.5, $samples[0]->getCpuPercent());
        self::assertSame(1024, $samples[0]->getMemoryUsedBytes());
        self::assertSame(4096, $samples[0]->getMemoryLimitBytes());

        $hostSamples = $this->entityManager->getRepository(HostSampleEntity::class)->findAll();
        self::assertCount(1, $hostSamples);
        self::assertSame(30.0, $hostSamples[0]->getCpuPercent());
        self::assertSame(2048, $hostSamples[0]->getMemoryUsedBytes());
        self::assertSame(8192, $hostSamples[0]->getMemoryTotalBytes());
        self::assertSame(4, $hostSamples[0]->getCpuCount());
        self::assertSame(3, $hostSamples[0]->getContainersTotal());
        self::assertSame(2, $hostSamples[0]->getContainersRunning());
        self::assertSame(1, $hostSamples[0]->getContainersStopped());
        self::assertSame(10, $hostSamples[0]->getImagesTotal());
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
        self::assertSame('Up 5 minutes', $containers[0]->getStatus());
        self::assertSame('/srv/shop', $containers[0]->getComposeWorkingDir());
        self::assertSame('/srv/shop/compose.yaml', $containers[0]->getComposeConfigFiles());
        self::assertSame('storefront', $containers[0]->getGroup());

        $samples = $this->entityManager->getRepository(ContainerSampleEntity::class)->findAll();
        self::assertCount(2, $samples);
    }

    public function testWriteKeepsContainerWithoutSample(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T10:00:00+00:00');

        $written = $this->writer->write(new DockerCollection(
            ['abc-123' => new ContainerSnapshot('abc-123', 'api', 'api:1', 'created', 'Created')],
            [],
            null,
        ));

        self::assertSame(0, $written);

        $containers = $this->entityManager->getRepository(Container::class)->findAll();
        self::assertCount(1, $containers);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
