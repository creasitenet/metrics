<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ContainerLogRepository;
use App\Repository\DataPointRepository;
use App\Repository\DataStateRepository;
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

    public function __construct(
        private readonly DataPointRepository $dataPointRepository,
        private readonly DataStateRepository $dataStateRepository,
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
        $points = $this->dataPointRepository->findForContainerSince($id, $since);
        $states = $this->dataStateRepository->findForContainerLatestStates($id);
        $dashboard = $this->seriesBuilder->buildDashboard($states, $points);
        $history = $dashboard['containers'][$id]
            ?? (array_values($dashboard['containers'])[0] ?? null);
        if (null === $history) {
            throw $this->createNotFoundException('Container not found.');
        }

        $fullId = '' !== $history['container_id'] ? $history['container_id'] : $id;
        $shortId = substr($fullId, 0, 12);

        $container = [
            'id' => $fullId,
            'short_id' => $shortId,
            'name' => $history['name'],
            'image' => $history['image'],
            'state' => '' !== $history['state'] ? $history['state'] : 'unknown',
            'status' => '' !== $history['status'] ? $history['status'] : 'unknown',
            'compose_project' => '' !== $history['compose_project'] ? $history['compose_project'] : null,
            'compose_service' => '' !== $history['compose_service'] ? $history['compose_service'] : null,
            'cpu_percent' => $history['metrics']['docker.container.cpu_percent']['latest'] ?? null,
            'memory_used_bytes' => $history['metrics']['docker.container.memory_used_bytes']['latest'] ?? null,
            'memory_limit_bytes' => $history['metrics']['docker.container.memory_limit_bytes']['latest'] ?? null,
        ];

        return $this->render('dashboard/container.html.twig', [
            'period' => $period,
            'container' => $container,
            'metrics' => $history['metrics'],
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

    /** @return array{period: string, groups: array<string, list<array<string, mixed>>>, container_count: int, host_series: array<string, mixed>, refresh_seconds: int} */
    private function dashboardViewData(Request $request): array
    {
        $period = $this->resolvePeriod($request);
        $since = new \DateTimeImmutable(self::PERIODS[$period]);
        $points = $this->dataPointRepository->findSince($since, 20000);
        $states = $this->dataStateRepository->findLatestStates();
        $dashboard = $this->seriesBuilder->buildDashboard($states, $points);
        $containers = $this->containersFromSeries($dashboard['containers']);

        return [
            'period' => $period,
            'groups' => $this->groupContainers($containers),
            'container_count' => \count($containers),
            'host_series' => $dashboard['host'],
            'refresh_seconds' => max(5, $this->refreshSeconds),
        ];
    }

    private function resolvePeriod(Request $request): string
    {
        $period = $request->query->getString('period', '24h');

        return isset(self::PERIODS[$period]) ? $period : '24h';
    }

    /**
     * @param array<string, array{
     *     name: string,
     *     image: string,
     *     container_id: string,
     *     compose_project: string,
     *     compose_service: string,
     *     state: string,
     *     status: string,
     *     health: string,
     *     ports: string,
     *     metrics: array<string, array{labels: list<string>, values: list<float>, latest: float, count: int}>
     * }> $series
     *
     * @return list<array{
     *     id: string,
     *     short_id: string,
     *     name: string,
     *     image: string,
     *     state: string,
     *     status: string,
     *     compose_project: ?string,
     *     compose_service: ?string,
     *     cpu_percent: ?float,
     *     memory_used_bytes: ?float,
     *     memory_limit_bytes: ?float
     * }>
     */
    private function containersFromSeries(array $series): array
    {
        $rows = [];
        foreach ($series as $item) {
            $containerId = $item['container_id'];
            $rows[] = [
                'id' => $containerId,
                'short_id' => '' !== $containerId ? substr($containerId, 0, 12) : 'unknown',
                'name' => $item['name'],
                'image' => $item['image'],
                'state' => '' !== $item['state'] ? $item['state'] : 'unknown',
                'status' => '' !== $item['status'] ? $item['status'] : 'unknown',
                'compose_project' => '' !== $item['compose_project'] ? $item['compose_project'] : null,
                'compose_service' => '' !== $item['compose_service'] ? $item['compose_service'] : null,
                'cpu_percent' => $item['metrics']['docker.container.cpu_percent']['latest'] ?? null,
                'memory_used_bytes' => $item['metrics']['docker.container.memory_used_bytes']['latest'] ?? null,
                'memory_limit_bytes' => $item['metrics']['docker.container.memory_limit_bytes']['latest'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{
     *     id: string,
     *     short_id: string,
     *     name: string,
     *     image: string,
     *     state: string,
     *     status: string,
     *     compose_project: ?string,
     *     compose_service: ?string,
     *     cpu_percent: ?float,
     *     memory_used_bytes: ?float,
     *     memory_limit_bytes: ?float
     * }> $containers
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupContainers(array $containers): array
    {
        $groups = [];
        foreach ($containers as $container) {
            $key = 'ungrouped';
            if (null !== $container['compose_project'] && null !== $container['compose_service']) {
                $key = $container['compose_project'].' / '.$container['compose_service'];
            } elseif (null !== $container['compose_project']) {
                $key = $container['compose_project'];
            }
            $groups[$key][] = $container;
        }
        ksort($groups);

        return $groups;
    }
}
