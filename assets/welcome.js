(() => {
    const STORAGE_KEY = "wos_welcome_seen_v1";
    const modal = document.getElementById("welcome-modal");
    if (!modal) {
        return;
    }

    let seen = false;
    try {
        seen = window.localStorage.getItem(STORAGE_KEY) === "1";
    } catch (e) {
        seen = false;
    }

    if (seen) {
        return;
    }

    const dismissButtons = modal.querySelectorAll("[data-welcome-dismiss]");

    function openWelcome() {
        modal.hidden = false;
        document.body.classList.add("welcome-modal-open");
        requestAnimationFrame(() => {
            modal.classList.add("is-visible");
        });
        const primary = modal.querySelector(".btn-primary");
        primary?.focus();
    }

    function closeWelcome() {
        modal.classList.remove("is-visible");
        document.body.classList.remove("welcome-modal-open");
        window.setTimeout(() => {
            modal.hidden = true;
        }, 220);
        try {
            window.localStorage.setItem(STORAGE_KEY, "1");
        } catch (e) {
            // ignore storage failures
        }
    }

    dismissButtons.forEach((btn) => {
        btn.addEventListener("click", (event) => {
            // Keep WhatsApp link working; only dismiss buttons close
            if (btn.tagName === "A") {
                return;
            }
            event.preventDefault();
            closeWelcome();
        });
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && !modal.hidden) {
            closeWelcome();
        }
    });

    // Small delay so the page paints first
    window.setTimeout(openWelcome, 280);
})();
