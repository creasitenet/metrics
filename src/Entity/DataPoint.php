<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DataPointRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DataPointRepository::class)]
#[ORM\Index(name: 'idx_data_point_metric_recorded', columns: ['metric_id', 'recorded_at'])]
#[ORM\Index(name: 'idx_data_point_recorded', columns: ['recorded_at'])]
#[ORM\Index(name: 'idx_data_point_container_recorded', columns: ['container_id', 'recorded_at'])]
class DataPoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @param array<string, scalar|null> $tags */
    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'dataPoints')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Metric $metric,

        #[ORM\Column(type: 'float')]
        private float $value,

        #[ORM\Column(type: 'json')]
        private array $tags,

        #[ORM\Column(type: 'datetimetz_immutable')]
        private \DateTimeImmutable $recordedAt,

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

    /** @return array<string, scalar|null> */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getContainerId(): ?string
    {
        return $this->containerId;
    }
}
