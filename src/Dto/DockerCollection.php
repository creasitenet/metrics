<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class DockerCollection
{
    /**
     * @param array<string, ContainerSnapshot> $containers keyed by Docker container id
     * @param array<string, ContainerSample>   $samples    keyed by Docker container id
     */
    public function __construct(
        public array $containers,
        public array $samples,
        public ?HostSample $host = null,
    ) {
    }

    public function sampleFor(string $containerId): ?ContainerSample
    {
        return $this->samples[$containerId] ?? null;
    }
}
