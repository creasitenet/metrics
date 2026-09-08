/**
 * Shared Chart.js helpers for HTMX-refreshed dashboard fragments.
 *
 * Fragments expose series as a hidden element:
 *   <div hidden data-metrics-charts="…" data-metrics="a,b" data-canvas-prefix="host-chart">JSON</div>
 *
 * Important: do not use <script type="application/json"> inside HTMX swaps — browsers/HTMX
 * can drop or ignore those nodes when injected via innerHTML/outerHTML.
 */
(() => {
  const chartOpts = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { display: false, grid: { display: false } },
      y: { ticks: { color: '#7e857d' }, grid: { color: 'rgba(255,255,255,.07)' } },
    },
  };

  /** @type {Map<string, {destroy: Function, resize: Function}>} */
  const instances = new Map();

  function destroyAll() {
    instances.forEach((chart) => {
      try {
        chart.destroy();
      } catch {
        // canvas may already be detached
      }
    });
    instances.clear();
  }

  /**
   * @param {ParentNode} root
   * @param {HTMLElement} payload
   */
  function renderPayload(root, payload) {
    if (!window.Chart) {
      return;
    }

    let series;
    try {
      series = JSON.parse(payload.textContent || '{}');
    } catch {
      return;
    }

    const prefix = payload.dataset.canvasPrefix || 'chart';
    const metrics = (payload.dataset.metrics || '').split(',').map((m) => m.trim()).filter(Boolean);

    metrics.forEach((name) => {
      const data = series[name];
      const canvasId = `${prefix}-${name.replaceAll('.', '-')}`;
      const canvas = root.querySelector(`#${CSS.escape(canvasId)}`);
      if (!data || !(canvas instanceof HTMLCanvasElement)) {
        return;
      }

      const existing = instances.get(canvasId);
      if (existing) {
        try {
          existing.destroy();
        } catch {
          // ignore
        }
        instances.delete(canvasId);
      }

      // Chart.js refuses a second Chart on the same canvas; clear any orphan binding.
      if (typeof window.Chart.getChart === 'function') {
        const orphan = window.Chart.getChart(canvas);
        if (orphan) {
          orphan.destroy();
        }
      }

      const chart = new window.Chart(canvas, {
        type: 'line',
        data: {
          labels: data.labels || [],
          datasets: [{
            data: data.values || [],
            borderColor: '#d7ff45',
            backgroundColor: 'rgba(215,255,69,.08)',
            fill: true,
            borderWidth: 2,
            pointRadius: (data.values || []).length > 100 ? 0 : 2,
            tension: 0.25,
          }],
        },
        options: chartOpts,
      });
      instances.set(canvasId, chart);
      requestAnimationFrame(() => chart.resize());
    });
  }

  /** @param {ParentNode} [root] */
  function boot(root = document) {
    const scope = root instanceof Element || root instanceof Document ? root : document;
    scope.querySelectorAll('[data-metrics-charts]').forEach((el) => {
      if (el instanceof HTMLElement) {
        renderPayload(scope, el);
      }
    });
  }

  function refreshAfterHtmx() {
    // outerHTML leaves Chart instances pointing at detached canvases with the same ids.
    destroyAll();
    // afterSettle: layout is done so canvas width/height are non-zero.
    boot(document);
  }

  document.addEventListener('DOMContentLoaded', () => boot(document));
  document.body.addEventListener('htmx:afterSettle', refreshAfterHtmx);
})();
