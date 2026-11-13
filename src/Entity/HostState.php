<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HostStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HostStateRepository::class)]
#[ORM\Table(name: 'host_state')]
class HostState
{
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(type: 'float')]
    private float $cpuPercent = 0.0;

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

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $sampledAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nodeName = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $serverVersion = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $uptimeSeconds = null;

    public function __construct(
        float $cpuPercent,
        ?int $memoryUsedBytes,
        ?int $memoryTotalBytes,
        ?int $cpuCount,
        ?int $containersTotal,
        ?int $containersRunning,
        ?int $containersStopped,
        ?int $imagesTotal,
        ?int $goroutines,
        ?int $fdsOpen,
        \DateTimeImmutable $sampledAt,
        ?string $nodeName = null,
        ?string $serverVersion = null,
        ?int $uptimeSeconds = null,
    ) {
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
        $this->sampledAt = $sampledAt;
        $this->nodeName = $nodeName;
        $this->serverVersion = $serverVersion;
        $this->uptimeSeconds = $uptimeSeconds;
    }

    public function refresh(
        float $cpuPercent,
        ?int $memoryUsedBytes,
        ?int $memoryTotalBytes,
        ?int $cpuCount,
        ?int $containersTotal,
        ?int $containersRunning,
        ?int $containersStopped,
        ?int $imagesTotal,
        ?int $goroutines,
        ?int $fdsOpen,
        \DateTimeImmutable $sampledAt,
        ?string $nodeName = null,
        ?string $serverVersion = null,
        ?int $uptimeSeconds = null,
    ): void {
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
        $this->sampledAt = $sampledAt;
        $this->nodeName = $nodeName;
        $this->serverVersion = $serverVersion;
        $this->uptimeSeconds = $uptimeSeconds;
    }

    public function getUptimeSeconds(): ?int
    {
        return $this->uptimeSeconds;
    }

    public function getNodeName(): ?string
    {
        return $this->nodeName;
    }

    public function getServerVersion(): ?string
    {
        return $this->serverVersion;
    }

    public function getId(): int
    {
        return $this->id;
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

    public function getSampledAt(): \DateTimeImmutable
    {
        return $this->sampledAt;
    }
}
