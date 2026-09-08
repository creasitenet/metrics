<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dto\MetricPoint;
use App\Entity\DataPoint;
use App\Entity\DataState;
use App\Enum\MetricType;
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

    public function testWriteHistoricalMetricPersistsDataPoints(): void
    {
        $now = new \DateTimeImmutable();
        $points = [
            new MetricPoint('cpu_usage', MetricType::GAUGE, 45.5, $now, ['host' => 'server-1']),
            new MetricPoint('cpu_usage', MetricType::GAUGE, 50.0, $now->modify('+10 seconds'), ['host' => 'server-1']),
        ];

        $written = $this->writer->write($points);

        self::assertSame(2, $written);

        $dataPoints = $this->entityManager->getRepository(DataPoint::class)->findAll();
        self::assertCount(2, $dataPoints);
        self::assertSame(45.5, $dataPoints[0]->getValue());
        self::assertSame(50.0, $dataPoints[1]->getValue());
    }

    public function testWriteStateMetricPerformsUpsertOnDataState(): void
    {
        $t1 = new \DateTimeImmutable('2026-01-01 10:00:00');
        $t2 = new \DateTimeImmutable('2026-01-01 10:00:30');

        $point1 = new MetricPoint('container_status', MetricType::STATE, 1.0, $t1, ['container_id' => 'abc-123']);
        $this->writer->write([$point1]);

        $states = $this->entityManager->getRepository(DataState::class)->findAll();
        self::assertCount(1, $states);
        self::assertSame(1.0, $states[0]->getValue());
        self::assertSame('abc-123', $states[0]->getContainerId());
        self::assertEquals($t1, $states[0]->getUpdatedAt());

        // second write for the same container updates the same row
        $point2 = new MetricPoint('container_status', MetricType::STATE, 0.0, $t2, ['container_id' => 'abc-123']);
        $this->writer->write([$point2]);

        $this->entityManager->clear();

        $statesAfterUpdate = $this->entityManager->getRepository(DataState::class)->findAll();
        self::assertCount(1, $statesAfterUpdate);
        self::assertSame(0.0, $statesAfterUpdate[0]->getValue());
        self::assertEquals($t2, $statesAfterUpdate[0]->getUpdatedAt());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
