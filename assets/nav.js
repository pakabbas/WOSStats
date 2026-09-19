(() => {
    const hamburger = document.getElementById("nav-hamburger");
    const drawer = document.getElementById("nav-drawer");
    if (!hamburger || !drawer) {
        return;
    }

    const closeBtns = drawer.querySelectorAll("[data-nav-close]");

    function openDrawer() {
        drawer.hidden = false;
        document.body.classList.add("nav-drawer-open");
        hamburger.setAttribute("aria-expanded", "true");
        hamburger.setAttribute("aria-label", "Close menu");
        requestAnimationFrame(() => drawer.classList.add("is-open"));
    }

    function closeDrawer() {
        drawer.classList.remove("is-open");
        document.body.classList.remove("nav-drawer-open");
        hamburger.setAttribute("aria-expanded", "false");
        hamburger.setAttribute("aria-label", "Open menu");
        window.setTimeout(() => {
            drawer.hidden = true;
        }, 220);
    }

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

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && !drawer.hidden) {
            closeDrawer();
        }
    });
})();
