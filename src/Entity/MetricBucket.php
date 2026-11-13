<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MetricBucketRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MetricBucketRepository::class)]
#[ORM\Table(name: 'metric_bucket')]
#[ORM\UniqueConstraint(name: 'uniq_metric_bucket_scope', columns: ['container_id', 'resolution', 'bucket_start'])]
#[ORM\Index(name: 'idx_metric_bucket_lookup', columns: ['container_id', 'resolution', 'bucket_start'])]
class MetricBucket
{
    /** Host-level buckets use an empty container id. */
    public const HOST_CONTAINER_ID = '';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private string $containerId = self::HOST_CONTAINER_ID;

    #[ORM\Column(length: 4)]
    private string $resolution;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $bucketStart;

    #[ORM\Column(type: 'float')]
    private float $cpuAvg = 0.0;

    #[ORM\Column(type: 'bigint')]
    private int $memUsedMax = 0;

    #[ORM\Column(type: 'integer')]
    private int $sampleCount = 0;

    public function __construct(
        string $containerId,
        string $resolution,
        \DateTimeImmutable $bucketStart,
        float $cpuAvg,
        int $memUsedMax,
        int $sampleCount = 1,
    ) {
        $this->containerId = $containerId;
        $this->resolution = $resolution;
        $this->bucketStart = $bucketStart;
        $this->cpuAvg = $cpuAvg;
        $this->memUsedMax = $memUsedMax;
        $this->sampleCount = $sampleCount;
    }

    public function addSample(float $cpu, int $memUsed): void
    {
        $total = $this->cpuAvg * $this->sampleCount + $cpu;
        ++$this->sampleCount;
        $this->cpuAvg = $total / $this->sampleCount;
        if ($memUsed > $this->memUsedMax) {
            $this->memUsedMax = $memUsed;
        }
    }

    public function getContainerId(): string
    {
        return $this->containerId;
    }

    public function getResolution(): string
    {
        return $this->resolution;
    }

    public function getBucketStart(): \DateTimeImmutable
    {
        return $this->bucketStart;
    }

    public function getCpuAvg(): float
    {
        return $this->cpuAvg;
    }

    public function getMemUsedMax(): int
    {
        return $this->memUsedMax;
    }

    public function getSampleCount(): int
    {
        return $this->sampleCount;
    }
}
