(function () {
    const table = document.getElementById('alliance-table') || document.getElementById('threat-table');
    const searchInput = document.getElementById('alliance-search') || document.getElementById('threat-search');
    const filterButtons = document.querySelectorAll('.filter-btn');
    let activeFilter = 'all';

    function applyFilters() {
        if (!table) {
            return;
        }

        const query = (searchInput?.value || '').trim().toLowerCase();
        const rows = table.querySelectorAll('tbody tr[data-name]');

        rows.forEach((row) => {
            const name = row.dataset.name || '';
            const tag = row.dataset.tag || '';
            const isNap = row.dataset.nap === '1';
            const matchesSearch = !query || name.includes(query) || tag.includes(query);
            const matchesFilter =
                activeFilter === 'all' ||
                (activeFilter === 'nap' && isNap) ||
                (activeFilter === 'non-nap' && !isNap);

            row.hidden = !(matchesSearch && matchesFilter);
        });
    }

    filterButtons.forEach((button) => {
        button.addEventListener('click', () => {
            filterButtons.forEach((btn) => btn.classList.remove('active'));
            button.classList.add('active');
            activeFilter = button.dataset.filter || 'all';
            applyFilters();
        });
    });

    searchInput?.addEventListener('input', applyFilters);

    const refreshBtn = document.getElementById('refresh-alliances');
    const refreshStatus = document.getElementById('refresh-alliances-status');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', async () => {
            if (refreshBtn.disabled) {
                return;
            }
            refreshBtn.disabled = true;
            refreshBtn.classList.add('is-loading');
            if (refreshStatus) {
                refreshStatus.textContent = 'Refreshing…';
                refreshStatus.classList.remove('is-error', 'is-success');
            }
            try {
                const res = await fetch('run.php?format=json', {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
                const data = await res.json().catch(() => null);
                if (!data?.ok) {
                    throw new Error(data?.error || data?.message || 'Update failed.');
                }
                if (refreshStatus) {
                    refreshStatus.textContent = 'Updated — reloading…';
                    refreshStatus.classList.add('is-success');
                }
                window.setTimeout(() => {
                    window.location.reload();
                }, 500);
            } catch (err) {
                refreshBtn.disabled = false;
                refreshBtn.classList.remove('is-loading');
                if (refreshStatus) {
                    refreshStatus.textContent = err?.message || 'Refresh failed.';
                    refreshStatus.classList.add('is-error');
                }
            }
        });
    }

    const playerTable = document.getElementById('player-table');
    const playerSearch = document.getElementById('player-search');

    function applyPlayerFilter() {
        if (!playerTable) {
            return;
        }

        const query = (playerSearch?.value || '').trim().toLowerCase();
        playerTable.querySelectorAll('tbody tr[data-name]').forEach((row) => {
            const name = row.dataset.name || '';
            const id = row.dataset.id || '';
            row.hidden = !(!query || name.includes(query) || id.includes(query));
        });
    }

    playerSearch?.addEventListener('input', applyPlayerFilter);

    const rosterTable = document.getElementById('roster-table');
    const rosterSearch = document.getElementById('roster-search');

    // roster.js owns search when the lazy-load table is present.
    if (rosterTable && !rosterTable.dataset.aid) {
        function applyRosterFilter() {
            const query = (rosterSearch?.value || '').trim().toLowerCase();
            rosterTable.querySelectorAll('tbody tr[data-name]').forEach((row) => {
                const name = row.dataset.name || '';
                const id = row.dataset.id || '';
                row.hidden = !(!query || name.includes(query) || id.includes(query));
            });
        }

        rosterSearch?.addEventListener('input', applyRosterFilter);
    }

    function formatPower(value) {
        if (value === null || Number.isNaN(value)) {
            return 'N/A';
        }
        if (value >= 1_000_000_000) {
            return (value / 1_000_000_000).toFixed(2) + 'B';
        }
        if (value >= 1_000_000) {
            return (value / 1_000_000).toFixed(2) + 'M';
        }
        if (value >= 1_000) {
            return (value / 1_000).toFixed(2) + 'K';
        }
        return String(value);
    }

    const chartDefaults = {
        responsive: true,
        maintainAspectRatio: false,
        spanGaps: true,
        scales: {
            x: {
                ticks: { color: '#8b9bb3', maxRotation: 0, autoSkipPadding: 12 },
                grid: { color: 'rgba(125, 168, 210, 0.08)' },
                border: { color: 'rgba(125, 168, 210, 0.18)' },
            },
            y: {
                ticks: {
                    color: '#8b9bb3',
                    callback: (value) => formatPower(Number(value)),
                },
                grid: { color: 'rgba(125, 168, 210, 0.1)' },
                border: { color: 'rgba(125, 168, 210, 0.18)' },
            },
        },
        plugins: {
            legend: {
                labels: { color: '#e8eef7', boxWidth: 12, usePointStyle: true },
            },
            tooltip: {
                backgroundColor: 'rgba(10, 16, 26, 0.95)',
                titleColor: '#e8eef7',
                bodyColor: '#c5d3e6',
                borderColor: 'rgba(94, 200, 255, 0.3)',
                borderWidth: 1,
                callbacks: {
                    label: (context) => (context.dataset.label || 'Power') + ': ' + formatPower(Number(context.parsed.y)),
                },
            },
        },
    };

    if (window.allianceChartData && window.Chart) {
        const canvas = document.getElementById('power-chart');
        if (canvas) {
            new Chart(canvas, {
                type: 'line',
                data: {
                    labels: window.allianceChartData.labels,
                    datasets: [{
                        label: 'Power',
                        data: window.allianceChartData.values,
                        borderColor: '#5ec8ff',
                        backgroundColor: 'rgba(94, 200, 255, 0.12)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointBackgroundColor: '#5ec8ff',
                        pointBorderColor: '#0a101a',
                        pointBorderWidth: 2,
                    }],
                },
                options: {
                    ...chartDefaults,
                    plugins: {
                        ...chartDefaults.plugins,
                        legend: { display: false },
                    },
                },
            });
        }
    }

    if (window.compareChartData && window.Chart) {
        const canvas = document.getElementById('compare-chart');
        if (canvas) {
            new Chart(canvas, {
                type: 'line',
                data: {
                    labels: window.compareChartData.labels,
                    datasets: [
                        {
                            label: window.compareChartData.seriesA.label,
                            data: window.compareChartData.seriesA.values,
                            borderColor: '#5ec8ff',
                            backgroundColor: 'rgba(94, 200, 255, 0.08)',
                            fill: false,
                            tension: 0.3,
                            pointRadius: 3,
                            pointBackgroundColor: '#5ec8ff',
                        },
                        {
                            label: window.compareChartData.seriesB.label,
                            data: window.compareChartData.seriesB.values,
                            borderColor: '#3dd6a5',
                            backgroundColor: 'rgba(61, 214, 165, 0.08)',
                            fill: false,
                            tension: 0.3,
                            pointRadius: 3,
                            pointBackgroundColor: '#3dd6a5',
                        },
                    ],
                },
                options: {
                    ...chartDefaults,
                    plugins: {
                        ...chartDefaults.plugins,
                        legend: { display: true, position: 'top', labels: chartDefaults.plugins.legend.labels },
                    },
                },
            });
        }
    }

    if (window.allianceBarChartData && window.Chart) {
        const canvas = document.getElementById('alliance-bar-chart');
        if (canvas) {
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: window.allianceBarChartData.labels,
                    datasets: [
                        {
                            label: 'Top player power',
                            data: window.allianceBarChartData.topPlayer,
                            backgroundColor: 'rgba(94, 200, 255, 0.75)',
                            borderColor: '#5ec8ff',
                            borderWidth: 1,
                            borderRadius: 6,
                        },
                        {
                            label: 'Median power',
                            data: window.allianceBarChartData.median,
                            backgroundColor: 'rgba(61, 214, 165, 0.75)',
                            borderColor: '#3dd6a5',
                            borderWidth: 1,
                            borderRadius: 6,
                        },
                    ],
                },
                options: {
                    ...chartDefaults,
                    plugins: {
                        ...chartDefaults.plugins,
                        legend: { display: true, position: 'top', labels: chartDefaults.plugins.legend.labels },
                    },
                },
            });
        }
    }
})();
