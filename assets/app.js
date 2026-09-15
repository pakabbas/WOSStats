(function () {
    const table = document.getElementById('alliance-table');
    const searchInput = document.getElementById('alliance-search');
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
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.08)',
                        fill: true,
                        tension: 0.25,
                        pointRadius: 3,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            ticks: {
                                callback: (value) => formatPower(Number(value)),
                            },
                        },
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (context) => 'Power: ' + formatPower(Number(context.parsed.y)),
                            },
                        },
                    },
                },
            });
        }
    }

    function formatPower(value) {
        if (value >= 1_000_000_000) {
            return (value / 1_000_000_000).toFixed(1).replace(/\.0$/, '') + 'B';
        }
        if (value >= 1_000_000) {
            return (value / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
        }
        if (value >= 1_000) {
            return (value / 1_000).toFixed(1).replace(/\.0$/, '') + 'K';
        }
        return String(value);
    }
})();
