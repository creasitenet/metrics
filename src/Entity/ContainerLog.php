<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContainerLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContainerLogRepository::class)]
#[ORM\Table(name: 'container_log')]
#[ORM\Index(name: 'idx_container_log_container_position', columns: ['container_id', 'position'])]
class ContainerLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 128)]
        private string $containerId,

        #[ORM\Column]
        private int $position,

        #[ORM\Column(length: 16)]
        private string $stream,

        #[ORM\Column(type: 'text')]
        private string $line,

        #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $recordedAt = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContainerId(): string
    {
        return $this->containerId;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getStream(): string
    {
        return $this->stream;
    }

    public function getLine(): string
    {
        return $this->line;
    }

    public function getRecordedAt(): ?\DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
