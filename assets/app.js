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
    const playerAllianceFilter = document.getElementById('player-alliance-filter');
    const playerFilterCount = document.getElementById('player-filter-count');

    function applyPlayerFilter() {
        if (!playerTable) {
            return;
        }

        const query = (playerSearch?.value || '').trim().toLowerCase();
        const alliance = (playerAllianceFilter?.value || '').trim().toLowerCase();
        const allianceFilterActive = alliance !== '';
        // When filtering by alliance, only use top 100 of the board (not full 200).
        const maxRankWhenAllianceFiltered = 100;
        let visible = 0;
        let total = 0;

        playerTable.querySelectorAll('tbody tr[data-name]').forEach((row) => {
            total += 1;
            const name = row.dataset.name || '';
            const id = row.dataset.id || '';
            const tag = (row.dataset.tag || '').trim().toLowerCase();
            const rank = parseInt(row.dataset.rank || '0', 10) || 0;
            const matchesSearch = !query || name.includes(query) || id.includes(query) || tag.includes(query);
            let matchesAlliance = true;
            if (alliance === '__none__') {
                matchesAlliance = tag === '';
            } else if (alliance) {
                matchesAlliance = tag === alliance;
            }
            const inTopScope = !allianceFilterActive || rank <= 0 || rank <= maxRankWhenAllianceFiltered;
            const show = matchesSearch && matchesAlliance && inTopScope;
            row.hidden = !show;
            // Class backup: mobile responsive-table sets display:block on tr,
            // which can override the HTML hidden attribute in some browsers.
            row.classList.toggle('is-filtered-out', !show);
            if (show) {
                visible += 1;
            }
        });

        if (playerFilterCount) {
            if (total === 0) {
                playerFilterCount.textContent = '';
            } else if (visible === total && !query && !alliance) {
                playerFilterCount.textContent = total + ' players';
            } else if (allianceFilterActive) {
                playerFilterCount.textContent = visible + ' in top 100';
            } else {
                playerFilterCount.textContent = visible + ' of ' + total;
            }
        }
    }

    function rebuildPlayerAllianceFilterOptions() {
        if (!playerTable || !playerAllianceFilter) {
            return;
        }

        const selected = playerAllianceFilter.value;
        const counts = new Map();
        playerTable.querySelectorAll('tbody tr[data-name]').forEach((row) => {
            const rank = parseInt(row.dataset.rank || '0', 10) || 0;
            // Dropdown counts reflect top-100 pool (same scope as alliance filter).
            if (rank > 100) {
                return;
            }
            const key = (row.dataset.tag || '').trim().toLowerCase();
            if (!key) {
                return;
            }
            const label = (row.dataset.tagLabel || row.dataset.tag || key).trim() || key;
            const prev = counts.get(key);
            if (prev) {
                prev.count += 1;
            } else {
                counts.set(key, { label, count: 1 });
            }
        });

        const sorted = Array.from(counts.entries()).sort((a, b) =>
            a[1].label.localeCompare(b[1].label, undefined, { sensitivity: 'base' })
        );

        const keep = new Set(['', '__none__']);
        playerAllianceFilter.querySelectorAll('option').forEach((opt) => {
            if (!keep.has(opt.value)) {
                opt.remove();
            }
        });

        sorted.forEach(([value, info]) => {
            const opt = document.createElement('option');
            opt.value = value;
            opt.textContent = info.label + ' (' + info.count + ')';
            playerAllianceFilter.appendChild(opt);
        });

        if (selected && (selected === '__none__' || counts.has(selected) || selected === '')) {
            playerAllianceFilter.value = selected;
        } else {
            playerAllianceFilter.value = '';
        }
    }

    playerSearch?.addEventListener('input', applyPlayerFilter);
    playerAllianceFilter?.addEventListener('change', applyPlayerFilter);
    playerAllianceFilter?.addEventListener('input', applyPlayerFilter);
    applyPlayerFilter();

    // Runtime alliance tags for the players board (batched + server-cached).
    async function fillPlayerAllianceTags() {
        if (!playerTable) {
            return;
        }

        // Prefer cells still missing a saved tag; also refresh all once/day via server TTL.
        const allCells = Array.from(playerTable.querySelectorAll('.player-alliance-cell[data-uid]'));
        const pendingCells = allCells.filter((cell) => cell.dataset.needsLookup === '1');
        // If everything is already saved on the board, still ping the server in one
        // small batch of missing-only — skip network entirely when none pending.
        // Daily re-check: if any saved tags exist, refresh a light sample is unnecessary;
        // full daily refresh happens the next time a tag is missing OR after TTL when
        // JS asks for pending. To honor once/day for everyone, request all UIDs when
        // none are pending but localStorage day key is stale.
        const dayKey = 'wos_alliance_refresh_day';
        const today = new Date().toISOString().slice(0, 10);
        const alreadyRefreshedToday = (() => {
            try {
                return window.localStorage.getItem(dayKey) === today;
            } catch (e) {
                return false;
            }
        })();

        let cells = pendingCells;
        if (pendingCells.length === 0) {
            if (alreadyRefreshedToday) {
                return;
            }
            cells = allCells;
        }

        const uids = cells
            .map((cell) => String(cell.dataset.uid || '').replace(/\D+/g, ''))
            .filter((uid) => uid !== '');

        if (uids.length === 0) {
            return;
        }

        const byUid = new Map(cells.map((cell) => [String(cell.dataset.uid || '').replace(/\D+/g, ''), cell]));
        const batchSize = 25;

        const renderAlliance = (cell, tag, allianceId) => {
            const row = cell.closest('tr');
            const cleanTag = (tag || '').trim();
            const cleanId = (allianceId || '').trim();
            if (row) {
                row.dataset.tag = cleanTag.toLowerCase();
                row.dataset.tagLabel = cleanTag;
            }
            cell.dataset.needsLookup = '0';
            cell.classList.remove('is-pending');
            cell.replaceChildren();
            if (!cleanTag) {
                const empty = document.createElement('span');
                empty.className = 'muted-cell';
                empty.textContent = '—';
                cell.appendChild(empty);
                return;
            }
            if (cleanId) {
                const link = document.createElement('a');
                link.href = 'alliance.php?id=' + encodeURIComponent(cleanId);
                link.className = 'alliance-link';
                link.textContent = cleanTag;
                cell.appendChild(link);
            } else {
                cell.textContent = cleanTag;
            }
        };

        for (let i = 0; i < uids.length; i += batchSize) {
            const batch = uids.slice(i, i + batchSize);
            try {
                const res = await fetch(
                    'player-alliance-lookup.php?uids=' + encodeURIComponent(batch.join(',')),
                    { headers: { Accept: 'application/json' }, cache: 'no-store' }
                );
                const data = await res.json().catch(() => null);
                if (!data?.ok || !data.players) {
                    batch.forEach((uid) => {
                        const cell = byUid.get(uid);
                        if (cell) {
                            renderAlliance(cell, '', '');
                        }
                    });
                    continue;
                }
                batch.forEach((uid) => {
                    const cell = byUid.get(uid);
                    if (!cell) {
                        return;
                    }
                    const info = data.players[uid] || {};
                    renderAlliance(cell, info.alliance_tag || '', info.alliance_id || '');
                });
            } catch (err) {
                batch.forEach((uid) => {
                    const cell = byUid.get(uid);
                    if (cell) {
                        renderAlliance(cell, '', '');
                    }
                });
            }
            rebuildPlayerAllianceFilterOptions();
            applyPlayerFilter();
        }

        rebuildPlayerAllianceFilterOptions();
        applyPlayerFilter();

        try {
            window.localStorage.setItem(dayKey, today);
        } catch (e) {
            // ignore storage failures
        }
    }

    fillPlayerAllianceTags();

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
