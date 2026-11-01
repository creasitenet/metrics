<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContainerSampleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContainerSampleRepository::class)]
#[ORM\Table(name: 'container_sample')]
#[ORM\Index(name: 'idx_container_sample_container_recorded', columns: ['container_id', 'recorded_at'])]
#[ORM\Index(name: 'idx_container_sample_recorded', columns: ['recorded_at'])]
class ContainerSample
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private string $containerId;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $recordedAt;

    #[ORM\Column(type: 'boolean')]
    private bool $running;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $cpuPercent = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $memoryUsedBytes = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $memoryLimitBytes = null;

    public function __construct(
        string $containerId,
        \DateTimeImmutable $recordedAt,
        bool $running,
        ?float $cpuPercent = null,
        ?int $memoryUsedBytes = null,
        ?int $memoryLimitBytes = null,
    ) {
        $this->containerId = $containerId;
        $this->recordedAt = $recordedAt;
        $this->running = $running;
        $this->cpuPercent = $cpuPercent;
        $this->memoryUsedBytes = $memoryUsedBytes;
        $this->memoryLimitBytes = $memoryLimitBytes;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContainerId(): string
    {
        return $this->containerId;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getCpuPercent(): ?float
    {
        return $this->cpuPercent;
    }

    public function getMemoryUsedBytes(): ?int
    {
        return $this->memoryUsedBytes;
    }

    public function getMemoryLimitBytes(): ?int
    {
        return $this->memoryLimitBytes;
    }
}
