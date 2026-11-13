<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\HostState;
use App\Metrics\ChartPeriod;
use App\Repository\ContainerLogRepository;
use App\Repository\ContainerRepository;
use App\Repository\HostStateRepository;
use App\Service\ChartSeriesProvider;
use App\Service\HostMetaPresenter;
use App\Service\SeriesBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    private const OTHER_GROUP = 'Other containers';

    public function __construct(
        private readonly ContainerRepository $containerRepository,
        private readonly HostStateRepository $hostStateRepository,
        private readonly ContainerLogRepository $containerLogRepository,
        private readonly SeriesBuilder $seriesBuilder,
        private readonly ChartSeriesProvider $chartSeries,
        private readonly HostMetaPresenter $hostMeta,
        #[Autowire('%app.collector.interval_seconds%')]
        private readonly int $refreshSeconds,
        #[Autowire('%app.metrics.log_tail%')]
        private readonly int $logTail,
    ) {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('dashboard/index.html.twig', $this->viewData($request));
    }

    #[Route('/partials/dashboard', name: 'app_dashboard_live', methods: ['GET'])]
    public function indexLive(Request $request): Response
    {
        return $this->render('dashboard/_app_live_oob.html.twig', $this->viewData($request));
    }

    #[Route('/containers/{id}', name: 'app_container_show', methods: ['GET'], requirements: ['id' => '[a-zA-Z0-9._:-]+'])]
    public function legacyContainerRedirect(string $id): RedirectResponse
    {
        return $this->redirectToRoute('app_dashboard', ['c' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(Request $request): array
    {
        $containers = $this->containerRepository->findAll();
        $hostState = $this->hostStateRepository->singleton();
        $hostChart = $this->chartSeries->hostGaugeSeries();
        $built = $this->seriesBuilder->buildDashboard($hostState, $containers, $hostChart);
        $rows = array_values($built['containers']);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        $selectedId = $this->resolveSelectedId($request, $rows);
        $selected = null;
        foreach ($rows as $row) {
            if ($row['id'] === $selectedId) {
                $selected = $row;
                break;
            }
        }

        $containerChart = '' !== $selectedId
            ? $this->chartSeries->containerGaugeSeries($selectedId)
            : [];

        return [
            'period' => ChartPeriod::DEFAULT,
            'host' => $built['host'],
            'host_state' => $hostState,
            'groups' => $this->buildSidebarGroups($rows, $selectedId),
            'container_count' => \count($rows),
            'selected' => $selected,
            'selected_id' => $selectedId,
            'container_chart' => $containerChart,
            'logs' => '' !== $selectedId
                ? $this->containerLogRepository->findLatestForContainer($selectedId, max(1, $this->logTail))
                : [],
            'refresh_seconds' => max(5, $this->refreshSeconds),
            'log_tail' => max(1, $this->logTail),
            'collector' => $this->collectorFeed($hostState),
            'host_meta' => $this->hostMeta->present($hostState, $built['host']),
        ];
    }

    /**
     * @return array{ok: bool, label: string, sampled_at: ?\DateTimeImmutable}
     */
    private function collectorFeed(?HostState $hostState): array
    {
        if (null === $hostState) {
            return ['ok' => false, 'label' => 'no data', 'sampled_at' => null];
        }

        $sampledAt = $hostState->getSampledAt();
        $maxAge = max(90, $this->refreshSeconds * 3);
        $age = time() - $sampledAt->getTimestamp();
        $ok = $age <= $maxAge;

        return [
            'ok' => $ok,
            'label' => $ok ? 'live' : 'stale',
            'sampled_at' => $sampledAt,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{name: string, slug: string, containers: list<array<string, mixed>>, cpu_percent: float, memory_used_bytes: int}>
     */
    private function buildSidebarGroups(array $rows, string $selectedId): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $name = (string) ($row['group'] ?? '');
            if ('' === $name) {
                $project = (string) ($row['compose_project'] ?? '');
                $name = '' !== $project ? $project : self::OTHER_GROUP;
            }
            $row['selected'] = $row['id'] === $selectedId;
            $groups[$name]['name'] = $name;
            $groups[$name]['slug'] = $this->groupSlug($name);
            $groups[$name]['containers'][] = $row;
            $groups[$name]['cpu_percent'] = ($groups[$name]['cpu_percent'] ?? 0.0) + (float) ($row['cpu_percent'] ?? 0.0);
            $groups[$name]['memory_used_bytes'] = ($groups[$name]['memory_used_bytes'] ?? 0) + (int) ($row['memory_used_bytes'] ?? 0);
        }

        $out = array_values($groups);
        usort($out, static function (array $a, array $b): int {
            $aOther = self::OTHER_GROUP === $a['name'];
            $bOther = self::OTHER_GROUP === $b['name'];
            if ($aOther !== $bOther) {
                return $aOther ? 1 : -1;
            }

            return strcmp($a['name'], $b['name']);
        });

        return $out;
    }

    private function groupSlug(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');

        return trim($slug, '-') ?: 'group';
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function resolveSelectedId(Request $request, array $rows): string
    {
        $wanted = $request->query->getString('c', '');
        if ('' !== $wanted) {
            foreach ($rows as $row) {
                $id = (string) $row['id'];
                if ($id === $wanted || str_starts_with($id, $wanted)) {
                    return $id;
                }
            }
        }

        foreach ($rows as $row) {
            if ($row['running'] ?? false) {
                return (string) $row['id'];
            }
        }

        return isset($rows[0]) ? (string) $rows[0]['id'] : '';
    }
}
