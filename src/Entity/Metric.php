<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MetricType;
use App\Repository\MetricRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MetricRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_metric_name', columns: ['name'])]
class Metric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, DataPoint> */
    #[ORM\OneToMany(targetEntity: DataPoint::class, mappedBy: 'metric', orphanRemoval: true)]
    private Collection $dataPoints;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 128)]
        private string $name,

        #[ORM\Column(length: 16, enumType: MetricType::class)]
        private MetricType $type,
    ) {
        $this->dataPoints = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): MetricType
    {
        return $this->type;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
