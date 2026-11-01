<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Container;
use App\Repository\ContainerLogRepository;
use App\Repository\ContainerRepository;
use App\Repository\ContainerSampleRepository;
use App\Repository\HostSampleRepository;
use App\Service\SeriesBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    private const PERIODS = [
        '1h' => '-1 hour',
        '24h' => '-24 hours',
        '7d' => '-7 days',
        '30d' => '-30 days',
    ];

    private const UNGROUPED = '__ungrouped__';

    public function __construct(
        private readonly ContainerRepository $containerRepository,
        private readonly ContainerSampleRepository $containerSampleRepository,
        private readonly HostSampleRepository $hostSampleRepository,
        private readonly ContainerLogRepository $containerLogRepository,
        private readonly SeriesBuilder $seriesBuilder,
        #[Autowire('%app.collector.interval_seconds%')]
        private readonly int $refreshSeconds,
        #[Autowire('%app.metrics.log_tail%')]
        private readonly int $logTail,
    ) {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('dashboard/index.html.twig', $this->dashboardViewData($request));
    }

    #[Route('/partials/dashboard', name: 'app_dashboard_live', methods: ['GET'])]
    public function indexLive(Request $request): Response
    {
        return $this->render('dashboard/_live.html.twig', $this->dashboardViewData($request));
    }

    #[Route('/containers/{id}', name: 'app_container_show', methods: ['GET'], requirements: ['id' => '[a-zA-Z0-9._:-]+'])]
    public function container(string $id, Request $request): Response
    {
        $period = $this->resolvePeriod($request);
        $since = new \DateTimeImmutable(self::PERIODS[$period]);

        $container = $this->findContainer($id);
        if (null === $container) {
            throw $this->createNotFoundException('Container not found.');
        }

        $samples = $this->containerSampleRepository->findForContainerSince($container->getId(), $since);
        $latest = $this->containerSampleRepository->latestForContainers([$container->getId()]);
        $sample = $latest[$container->getId()] ?? null;
        $metrics = $this->seriesBuilder->buildContainerSeries($samples);
        $fullId = $container->getId();

        return $this->render('dashboard/container.html.twig', [
            'period' => $period,
            'container' => [
                'id' => $fullId,
                'short_id' => substr($fullId, 0, 12),
                'name' => $container->getName(),
                'image' => $container->getImage(),
                'state' => $container->getState(),
                'status' => $container->getStatus(),
                'compose_project' => $container->getComposeProject(),
                'compose_service' => $container->getComposeService(),
                'compose_working_dir' => $container->getComposeWorkingDir(),
                'group' => $container->getGroup(),
                'running' => $sample?->isRunning() ?? false,
                'cpu_percent' => $sample?->getCpuPercent(),
                'memory_used_bytes' => $sample?->getMemoryUsedBytes(),
                'memory_limit_bytes' => $sample?->getMemoryLimitBytes(),
            ],
            'metrics' => $metrics,
            'logs' => $this->containerLogRepository->findLatestForContainer($fullId, max(1, $this->logTail)),
            'refresh_seconds' => max(5, $this->refreshSeconds),
            'log_tail' => max(1, $this->logTail),
        ]);
    }

    #[Route('/partials/containers/{id}/logs', name: 'app_container_logs_live', methods: ['GET'], requirements: ['id' => '[a-zA-Z0-9._:-]+'])]
    public function containerLogsLive(string $id): Response
    {
        $tail = max(1, $this->logTail);

        return $this->render('dashboard/_logs.html.twig', [
            'container_id' => $id,
            'logs' => $this->containerLogRepository->findLatestForContainer($id, $tail),
            'refresh_seconds' => max(5, $this->refreshSeconds),
            'log_tail' => $tail,
        ]);
    }

    /** @return array{period: string, groups: list<array<string, mixed>>, container_count: int, host_series: array<string, mixed>, refresh_seconds: int} */
    private function dashboardViewData(Request $request): array
    {
        $period = $this->resolvePeriod($request);
        $since = new \DateTimeImmutable(self::PERIODS[$period]);

        $containers = $this->containerRepository->findAll();
        $latest = $this->containerSampleRepository->latestForContainers(
            array_map(static fn (Container $c): string => $c->getId(), $containers),
        );
        $hostSeries = $this->seriesBuilder->buildDashboard($containers, $latest, $this->hostSampleRepository->findSince($since));

        $rows = [];
        foreach ($hostSeries['containers'] as $id => $row) {
            $rows[$id] = $row;
        }

        return [
            'period' => $period,
            'groups' => $this->groupContainers($rows),
            'container_count' => \count($rows),
            'host_series' => $hostSeries['host'],
            'refresh_seconds' => max(5, $this->refreshSeconds),
        ];
    }

    private function resolvePeriod(Request $request): string
    {
        $period = $request->query->getString('period', '24h');

        return isset(self::PERIODS[$period]) ? $period : '24h';
    }

    private function findContainer(string $id): ?Container
    {
        $container = $this->containerRepository->find($id);
        if (null !== $container) {
            return $container;
        }

        foreach ($this->containerRepository->findAll() as $candidate) {
            if (str_starts_with($candidate->getId(), $id)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Builds the host → Compose folder → Compose project → service tree.
     *
     * @param array<string, array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function groupContainers(array $rows): array
    {
        $folders = [];

        foreach ($rows as $row) {
            $folderKey = $this->folderKey($row);
            $projectKey = $this->projectKey($row);
            $serviceKey = $this->serviceKey($row);

            $folder = $folders[$folderKey] ?? $this->emptyFolder($folderKey);
            $project = $folder['projects'][$projectKey] ?? $this->emptyProject($projectKey);
            $service = $project['services'][$serviceKey] ?? $this->emptyService($serviceKey);

            $service['containers'][] = $this->containerRow($row);
            $service['cpu_percent'] += (float) ($row['cpu_percent'] ?? 0.0);
            $service['memory_used_bytes'] += (int) ($row['memory_used_bytes'] ?? 0);
            $service['memory_limit_bytes'] += (int) ($row['memory_limit_bytes'] ?? 0);
            $service['container_count'] = \count($service['containers']);

            $project['services'][$serviceKey] = $service;
            $project['cpu_percent'] += (float) ($row['cpu_percent'] ?? 0.0);
            $project['memory_used_bytes'] += (int) ($row['memory_used_bytes'] ?? 0);
            $project['memory_limit_bytes'] += (int) ($row['memory_limit_bytes'] ?? 0);
            ++$project['container_count'];

            $folder['projects'][$projectKey] = $project;
            $folder['cpu_percent'] += (float) ($row['cpu_percent'] ?? 0.0);
            $folder['memory_used_bytes'] += (int) ($row['memory_used_bytes'] ?? 0);
            $folder['memory_limit_bytes'] += (int) ($row['memory_limit_bytes'] ?? 0);
            ++$folder['container_count'];

            $folders[$folderKey] = $folder;
        }

        $grouped = [];
        foreach ($folders as $folder) {
            $folder['projects'] = array_values($folder['projects']);
            foreach ($folder['projects'] as $index => $project) {
                $folder['projects'][$index]['services'] = array_values($project['services']);
            }
            $grouped[] = $folder;
        }

        usort($grouped, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $grouped;
    }

    /** @param array<string, mixed> $row */
    private function folderKey(array $row): string
    {
        $workingDir = $row['compose_working_dir'] ?? null;

        return \is_string($workingDir) && '' !== $workingDir ? $workingDir : self::UNGROUPED;
    }

    /** @param array<string, mixed> $row */
    private function projectKey(array $row): string
    {
        $group = $row['group'] ?? null;
        if (\is_string($group) && '' !== $group) {
            return $group;
        }

        $project = $row['compose_project'] ?? null;

        return \is_string($project) && '' !== $project ? $project : self::UNGROUPED;
    }

    /** @param array<string, mixed> $row */
    private function serviceKey(array $row): string
    {
        $service = $row['compose_service'] ?? null;
        if (\is_string($service) && '' !== $service) {
            return $service;
        }

        $name = $row['name'] ?? '';

        return \is_string($name) && '' !== $name ? $name : self::UNGROUPED;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFolder(string $key): array
    {
        return [
            'key' => $key,
            'label' => self::UNGROUPED === $key ? 'Other containers' : $this->folderLabel($key),
            'path' => self::UNGROUPED === $key ? null : $key,
            'projects' => [],
            'cpu_percent' => 0.0,
            'memory_used_bytes' => 0,
            'memory_limit_bytes' => 0,
            'container_count' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyProject(string $key): array
    {
        return [
            'key' => $key,
            'label' => self::UNGROUPED === $key ? 'No Compose project' : $key,
            'services' => [],
            'cpu_percent' => 0.0,
            'memory_used_bytes' => 0,
            'memory_limit_bytes' => 0,
            'container_count' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyService(string $key): array
    {
        return [
            'key' => $key,
            'label' => self::UNGROUPED === $key ? 'No service' : $key,
            'containers' => [],
            'cpu_percent' => 0.0,
            'memory_used_bytes' => 0,
            'memory_limit_bytes' => 0,
            'container_count' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function containerRow(array $row): array
    {
        $id = (string) $row['id'];

        return [
            'id' => $id,
            'short_id' => substr($id, 0, 12),
            'name' => (string) $row['name'],
            'image' => (string) $row['image'],
            'state' => (string) $row['state'],
            'status' => (string) $row['status'],
            'running' => (bool) $row['running'],
            'cpu_percent' => $row['cpu_percent'],
            'memory_used_bytes' => $row['memory_used_bytes'],
            'memory_limit_bytes' => $row['memory_limit_bytes'],
        ];
    }

    private function folderLabel(string $path): string
    {
        $trimmed = rtrim($path, '/');
        if ('' === $trimmed) {
            return $path;
        }

        $segments = explode('/', $trimmed);

        return $segments[array_key_last($segments)];
    }
}
