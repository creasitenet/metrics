<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContainerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContainerRepository::class)]
#[ORM\Table(name: 'container')]
#[ORM\Index(name: 'idx_container_compose_project', columns: ['compose_project'])]
#[ORM\Index(name: 'idx_container_working_dir', columns: ['compose_working_dir'])]
class Container
{
    #[ORM\Id]
    #[ORM\Column(length: 128)]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $image;

    #[ORM\Column(length: 32)]
    private string $state;

    #[ORM\Column(length: 255)]
    private string $status;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $composeProject;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $composeService;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $composeWorkingDir;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $composeConfigFiles;

    #[ORM\Column(name: 'dozzle_group', length: 255, nullable: true)]
    private ?string $group;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        string $name,
        string $image,
        string $state,
        string $status,
        ?string $composeProject = null,
        ?string $composeService = null,
        ?string $composeWorkingDir = null,
        ?string $composeConfigFiles = null,
        ?string $group = null,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->image = $image;
        $this->state = $state;
        $this->status = $status;
        $this->composeProject = $composeProject;
        $this->composeService = $composeService;
        $this->composeWorkingDir = $composeWorkingDir;
        $this->composeConfigFiles = $composeConfigFiles;
        $this->group = $group;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getImage(): string
    {
        return $this->image;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getComposeProject(): ?string
    {
        return $this->composeProject;
    }

    public function getComposeService(): ?string
    {
        return $this->composeService;
    }

    public function getComposeWorkingDir(): ?string
    {
        return $this->composeWorkingDir;
    }

    public function getComposeConfigFiles(): ?string
    {
        return $this->composeConfigFiles;
    }

    public function getGroup(): ?string
    {
        return $this->group;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function setImage(string $image): self
    {
        $this->image = $image;

        return $this;
    }

    public function setState(string $state): self
    {
        $this->state = $state;

        return $this;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function setComposeProject(?string $composeProject): self
    {
        $this->composeProject = $composeProject;

        return $this;
    }

    public function setComposeService(?string $composeService): self
    {
        $this->composeService = $composeService;

        return $this;
    }

    public function setComposeWorkingDir(?string $composeWorkingDir): self
    {
        $this->composeWorkingDir = $composeWorkingDir;

        return $this;
    }

    public function setComposeConfigFiles(?string $composeConfigFiles): self
    {
        $this->composeConfigFiles = $composeConfigFiles;

        return $this;
    }

    public function setGroup(?string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
