/**
 * Shared Chart.js helpers for HTMX-refreshed dashboard fragments.
 */
(() => {
  /** @param {number} bytes */
  function formatBytes(bytes) {
    const n = Number(bytes);
    if (!Number.isFinite(n) || n < 0) return '';
    if (n >= 1024 ** 3) return `${(n / 1024 ** 3).toFixed(1)} GiB`;
    if (n >= 1024 ** 2) return `${Math.round(n / 1024 ** 2)} MiB`;
    if (n >= 1024) return `${Math.round(n / 1024)} KiB`;
    return `${Math.round(n)} B`;
  }

  /**
   * @param {string} metricName
   */
  function chartOptions(metricName) {
    const isBytes = metricName.endsWith('_bytes');
    const isPercent = metricName.endsWith('_percent');
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { display: false, grid: { display: false } },
        y: {
          ticks: {
            color: '#8292a6',
            maxTicksLimit: 4,
            callback: isBytes
              ? (/** @type {string|number} */ value) => formatBytes(value)
              : isPercent
                ? (/** @type {string|number} */ value) => `${value}%`
                : (/** @type {string|number} */ value) => String(value),
          },
          grid: { color: 'rgba(255,255,255,.06)' },
        },
      },
    };
  }

  /** @type {Map<string, import('chart.js').Chart>} */
  const instances = new Map();

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

      const labels = data.labels || [];
      const values = data.values || [];

      let chart = instances.get(canvasId);
      if (typeof window.Chart.getChart === 'function') {
        chart = chart || window.Chart.getChart(canvas) || undefined;
      }

      if (chart) {
        chart.data.labels = labels;
        chart.data.datasets[0].data = values;
        chart.data.datasets[0].pointRadius = values.length > 100 ? 0 : 2;
        chart.update('none');
        requestAnimationFrame(() => chart.resize());
        instances.set(canvasId, chart);
        return;
      }

      chart = new window.Chart(canvas, {
        type: 'line',
        data: {
          labels,
          datasets: [{
            data: values,
            borderColor: '#45d6b3',
            backgroundColor: 'rgba(69, 214, 179, .12)',
            fill: true,
            borderWidth: 2,
            pointRadius: values.length > 100 ? 0 : 2,
            tension: 0.25,
          }],
        },
        options: chartOptions(name),
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

  function pruneDetachedCharts() {
    for (const [id, chart] of instances.entries()) {
      const attached = chart.canvas?.isConnected;
      if (!attached) {
        try {
          chart.destroy();
        } catch {
          // ignore
        }
        instances.delete(id);
      }
    }
  }

  function refreshAfterHtmx() {
    pruneDetachedCharts();
    boot(document);
  }

  document.addEventListener('DOMContentLoaded', () => boot(document));
  document.body.addEventListener('htmx:afterSettle', refreshAfterHtmx);
})();
