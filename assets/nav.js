(() => {
    const hamburger = document.getElementById("nav-hamburger");
    const drawer = document.getElementById("nav-drawer");
    const moreToggle = document.getElementById("nav-more-toggle");
    const moreMenu = document.getElementById("nav-more-menu");
    const moreWrap = moreToggle ? moreToggle.closest(".nav-more") : null;
    const fab = document.getElementById("mobile-fab");
    const fabToggle = document.getElementById("mobile-fab-toggle");
    const fabBackdrop = fab ? fab.querySelector("[data-fab-close]") : null;

    function openMore() {
        if (!moreToggle || !moreMenu) {
            return;
        }
        moreMenu.hidden = false;
        moreToggle.setAttribute("aria-expanded", "true");
        moreWrap?.classList.add("is-open");
    }

    function closeMore() {
        if (!moreToggle || !moreMenu) {
            return;
        }
        moreMenu.hidden = true;
        moreToggle.setAttribute("aria-expanded", "false");
        moreWrap?.classList.remove("is-open");
    }

    function openFab() {
        if (!fab || !fabToggle) {
            return;
        }
        fab.classList.add("is-open");
        fabToggle.setAttribute("aria-expanded", "true");
        fabToggle.setAttribute("aria-label", "Close quick options");
        document.body.classList.add("mobile-fab-open");
    }

    function closeFab() {
        if (!fab || !fabToggle) {
            return;
        }
        fab.classList.remove("is-open");
        fabToggle.setAttribute("aria-expanded", "false");
        fabToggle.setAttribute("aria-label", "Open quick options");
        document.body.classList.remove("mobile-fab-open");
    }

    if (moreToggle && moreMenu) {
        moreToggle.addEventListener("click", (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (moreMenu.hidden) {
                openMore();
            } else {
                closeMore();
            }
        });

        document.addEventListener("click", (event) => {
            if (!moreWrap || moreMenu.hidden) {
                return;
            }
            if (!moreWrap.contains(event.target)) {
                closeMore();
            }
        });
    }

    if (fabToggle && fab) {
        fabToggle.addEventListener("click", (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (fab.classList.contains("is-open")) {
                closeFab();
            } else {
                closeMore();
                openFab();
            }
        });

        fabBackdrop?.addEventListener("click", (event) => {
            event.preventDefault();
            closeFab();
        });
    }

    function openDrawer() {
        if (!hamburger || !drawer) {
            return;
        }
        closeFab();
        drawer.hidden = false;
        document.body.classList.add("nav-drawer-open");
        hamburger.setAttribute("aria-expanded", "true");
        hamburger.setAttribute("aria-label", "Close menu");
        requestAnimationFrame(() => drawer.classList.add("is-open"));
    }

    function closeDrawer() {
        if (!hamburger || !drawer) {
            return;
        }
        drawer.classList.remove("is-open");
        document.body.classList.remove("nav-drawer-open");
        hamburger.setAttribute("aria-expanded", "false");
        hamburger.setAttribute("aria-label", "Open menu");
        window.setTimeout(() => {
            drawer.hidden = true;
        }, 220);
    }

    if (hamburger && drawer) {
        const closeBtns = drawer.querySelectorAll("[data-nav-close]");

        hamburger.addEventListener("click", () => {
            if (drawer.hidden) {
                openDrawer();
            } else {
                closeDrawer();
            }
        });

        closeBtns.forEach((btn) => {
            btn.addEventListener("click", (event) => {
                event.preventDefault();
                closeDrawer();
            });
        });
    }

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            closeMore();
            closeFab();
            if (drawer && !drawer.hidden) {
                closeDrawer();
            }
        }
    });
})();
