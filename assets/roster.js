(function () {
    const table = document.getElementById('roster-table');
    if (!table) {
        return;
    }

    const pageSize = Math.max(1, Number(table.dataset.pageSize || 20));
    const seeMoreBtn = document.getElementById('roster-see-more');
    const rows = Array.from(table.querySelectorAll('tbody tr.roster-row'));
    let visibleCount = Math.min(pageSize, rows.length);

    function updateSeeMoreLabel() {
        if (!seeMoreBtn) {
            return;
        }
        const remaining = Math.max(0, rows.length - visibleCount);
        if (remaining <= 0) {
            seeMoreBtn.hidden = true;
            return;
        }
        seeMoreBtn.hidden = false;
        seeMoreBtn.textContent = `See more (${remaining} left)`;
    }

    seeMoreBtn?.addEventListener('click', () => {
        const next = Math.min(rows.length, visibleCount + pageSize);
        for (let i = visibleCount; i < next; i++) {
            rows[i].hidden = false;
            rows[i].classList.remove('is-search-hidden');
        }
        visibleCount = next;
        updateSeeMoreLabel();
    });

    const rosterSearch = document.getElementById('roster-search');
    rosterSearch?.addEventListener('input', () => {
        const query = (rosterSearch.value || '').trim().toLowerCase();
        if (!query) {
            rows.forEach((row, index) => {
                row.classList.remove('is-search-hidden');
                row.hidden = index >= visibleCount;
            });
            updateSeeMoreLabel();
            return;
        }

        rows.forEach((row) => {
            const name = row.dataset.name || '';
            const id = row.dataset.id || '';
            const match = name.includes(query) || id.includes(query);
            row.classList.toggle('is-search-hidden', !match);
            row.hidden = !match;
        });
        if (seeMoreBtn) {
            seeMoreBtn.hidden = true;
        }
    });

    updateSeeMoreLabel();
})();
