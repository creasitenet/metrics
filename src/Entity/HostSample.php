<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HostSampleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HostSampleRepository::class)]
#[ORM\Table(name: 'host_sample')]
#[ORM\Index(name: 'idx_host_sample_recorded', columns: ['recorded_at'])]
class HostSample
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $recordedAt;

    #[ORM\Column(type: 'float')]
    private float $cpuPercent;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $memoryUsedBytes = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $memoryTotalBytes = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $cpuCount = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $containersTotal = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $containersRunning = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $containersStopped = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $imagesTotal = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $goroutines = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fdsOpen = null;

    public function __construct(
        \DateTimeImmutable $recordedAt,
        float $cpuPercent = 0.0,
        ?int $memoryUsedBytes = null,
        ?int $memoryTotalBytes = null,
        ?int $cpuCount = null,
        ?int $containersTotal = null,
        ?int $containersRunning = null,
        ?int $containersStopped = null,
        ?int $imagesTotal = null,
        ?int $goroutines = null,
        ?int $fdsOpen = null,
    ) {
        $this->recordedAt = $recordedAt;
        $this->cpuPercent = $cpuPercent;
        $this->memoryUsedBytes = $memoryUsedBytes;
        $this->memoryTotalBytes = $memoryTotalBytes;
        $this->cpuCount = $cpuCount;
        $this->containersTotal = $containersTotal;
        $this->containersRunning = $containersRunning;
        $this->containersStopped = $containersStopped;
        $this->imagesTotal = $imagesTotal;
        $this->goroutines = $goroutines;
        $this->fdsOpen = $fdsOpen;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getCpuPercent(): float
    {
        return $this->cpuPercent;
    }

    public function getMemoryUsedBytes(): ?int
    {
        return $this->memoryUsedBytes;
    }

    public function getMemoryTotalBytes(): ?int
    {
        return $this->memoryTotalBytes;
    }

    public function getCpuCount(): ?int
    {
        return $this->cpuCount;
    }

    public function getContainersTotal(): ?int
    {
        return $this->containersTotal;
    }

    public function getContainersRunning(): ?int
    {
        return $this->containersRunning;
    }

    public function getContainersStopped(): ?int
    {
        return $this->containersStopped;
    }

    public function getImagesTotal(): ?int
    {
        return $this->imagesTotal;
    }

    public function getGoroutines(): ?int
    {
        return $this->goroutines;
    }

    public function getFdsOpen(): ?int
    {
        return $this->fdsOpen;
    }
}
