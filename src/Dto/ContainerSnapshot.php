<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ContainerSnapshot
{
    public function __construct(
        public string $id,
        public string $name,
        public string $image,
        public string $state,
        public string $status,
        public ?string $composeProject = null,
        public ?string $composeService = null,
        public ?string $composeWorkingDir = null,
        public ?string $composeConfigFiles = null,
        public ?string $group = null,
    ) {
    }
}
