<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DataStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DataStateRepository::class)]
#[ORM\Table(name: 'data_state')]
#[ORM\Index(name: 'idx_data_state_container_metric', columns: ['container_id', 'metric_id'])]
#[ORM\UniqueConstraint(name: 'uniq_data_state_metric_container', columns: ['metric_id', 'container_id'])]
class DataState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @param array<string, scalar|null> $tags */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Metric::class)]
        #[ORM\JoinColumn(name: 'metric_id', nullable: false, onDelete: 'CASCADE')]
        private Metric $metric,

        #[ORM\Column(type: 'float')]
        private float $value,

        #[ORM\Column(type: 'json')]
        private array $tags,

        #[ORM\Column(type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,

        #[ORM\Column(length: 128, nullable: true)]
        private ?string $containerId = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMetric(): Metric
    {
        return $this->metric;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function setValue(float $value): self
    {
        $this->value = $value;

        return $this;
    }

    /** @return array<string, scalar|null> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @param array<string, scalar|null> $tags */
    public function setTags(array $tags): self
    {
        $this->tags = $tags;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getContainerId(): ?string
    {
        return $this->containerId;
    }

    public function setContainerId(?string $containerId): self
    {
        $this->containerId = $containerId;

        return $this;
    }
}
