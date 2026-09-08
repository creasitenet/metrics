<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dto\MetricPoint;
use App\Entity\ContainerLog;
use App\Entity\DataPoint;
use App\Entity\DataState;
use App\Enum\MetricType;
use App\Metrics\ContainerLogsWriter;
use App\Metrics\MetricsWriter;
use App\Repository\ContainerLogRepository;
use App\Repository\DataPointRepository;
use App\Repository\DataStateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrphanContainerPruneTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MetricsWriter $metricsWriter;
    private ContainerLogsWriter $logsWriter;
    private DataStateRepository $dataStates;
    private DataPointRepository $dataPoints;
    private ContainerLogRepository $containerLogs;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->metricsWriter = $container->get(MetricsWriter::class);
        $this->logsWriter = $container->get(ContainerLogsWriter::class);
        $this->dataStates = $container->get(DataStateRepository::class);
        $this->dataPoints = $container->get(DataPointRepository::class);
        $this->containerLogs = $container->get(ContainerLogRepository::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testDeleteOrphanedKeepsLiveContainersAndHostRows(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T12:00:00Z');
        $liveId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $goneId = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $this->metricsWriter->write([
            new MetricPoint('docker.host.memory_used_bytes', MetricType::GAUGE, 100.0, $now),
            new MetricPoint('docker.containers.running', MetricType::STATE, 1.0, $now),
            new MetricPoint('docker.container.cpu_percent', MetricType::GAUGE, 10.0, $now, ['container_id' => $liveId]),
            new MetricPoint('docker.container.running', MetricType::STATE, 1.0, $now, ['container_id' => $liveId]),
            new MetricPoint('docker.container.cpu_percent', MetricType::GAUGE, 20.0, $now, ['container_id' => $goneId]),
            new MetricPoint('docker.container.running', MetricType::STATE, 1.0, $now, ['container_id' => $goneId]),
        ]);

        $this->logsWriter->replace($liveId, [
            ['stream' => 'stdout', 'line' => 'live', 'recordedAt' => $now],
        ]);
        $this->logsWriter->replace($goneId, [
            ['stream' => 'stdout', 'line' => 'gone', 'recordedAt' => $now],
        ]);

        $this->entityManager->clear();

        self::assertSame(1, $this->dataStates->deleteOrphanedByContainerIds([$liveId]));
        self::assertSame(1, $this->dataPoints->deleteOrphanedByContainerIds([$liveId]));
        self::assertSame(1, $this->containerLogs->deleteOrphanedByContainerIds([$liveId]));

        $this->entityManager->clear();

        $states = $this->entityManager->getRepository(DataState::class)->findAll();
        $stateIds = array_map(static fn (DataState $s): ?string => $s->getContainerId(), $states);
        sort($stateIds);
        self::assertSame([null, $liveId], $stateIds);

        $points = $this->entityManager->getRepository(DataPoint::class)->findAll();
        $pointIds = array_map(static fn (DataPoint $p): ?string => $p->getContainerId(), $points);
        sort($pointIds);
        self::assertSame([null, $liveId], $pointIds);

        $logs = $this->entityManager->getRepository(ContainerLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertSame($liveId, $logs[0]->getContainerId());
    }

    public function testDeleteOrphanedWithEmptyKeepListRemovesAllPerContainerRows(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T12:00:00Z');
        $goneId = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

        $this->metricsWriter->write([
            new MetricPoint('docker.host.cpus', MetricType::STATE, 4.0, $now),
            new MetricPoint('docker.container.memory_usage_bytes', MetricType::GAUGE, 1.0, $now, ['container_id' => $goneId]),
            new MetricPoint('docker.container.running', MetricType::STATE, 1.0, $now, ['container_id' => $goneId]),
        ]);
        $this->logsWriter->replace($goneId, [
            ['stream' => 'stdout', 'line' => 'x', 'recordedAt' => null],
        ]);

        self::assertSame(1, $this->dataStates->deleteOrphanedByContainerIds([]));
        self::assertSame(1, $this->dataPoints->deleteOrphanedByContainerIds([]));
        self::assertSame(1, $this->containerLogs->deleteOrphanedByContainerIds([]));

        $this->entityManager->clear();

        $states = $this->entityManager->getRepository(DataState::class)->findAll();
        self::assertCount(1, $states);
        self::assertNull($states[0]->getContainerId());

        self::assertCount(0, $this->entityManager->getRepository(DataPoint::class)->findAll());
        self::assertCount(0, $this->entityManager->getRepository(ContainerLog::class)->findAll());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
