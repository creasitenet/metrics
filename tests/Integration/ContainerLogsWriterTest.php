<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\ContainerLog;
use App\Metrics\ContainerLogsWriter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContainerLogsWriterTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ContainerLogsWriter $writer;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->writer = $container->get(ContainerLogsWriter::class);

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testReplaceIsBoundedAndIdempotent(): void
    {
        $id = 'abc123fullid';
        $this->writer->replace($id, [
            ['stream' => 'stdout', 'line' => 'first', 'recordedAt' => new \DateTimeImmutable('2024-01-01T00:00:00Z')],
            ['stream' => 'stderr', 'line' => 'second', 'recordedAt' => null],
        ]);

        $this->writer->replace($id, [
            ['stream' => 'stdout', 'line' => 'only', 'recordedAt' => null],
        ]);

        $this->entityManager->clear();
        $rows = $this->entityManager->getRepository(ContainerLog::class)->findBy(
            ['containerId' => $id],
            ['position' => 'ASC'],
        );

        self::assertCount(1, $rows);
        self::assertSame(0, $rows[0]->getPosition());
        self::assertSame('only', $rows[0]->getLine());
        self::assertSame('stdout', $rows[0]->getStream());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
