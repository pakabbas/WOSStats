(() => {
    const gate = document.getElementById("admin-gate");
    if (!gate) {
        return;
    }
    if (gate.dataset.signedIn === "1") {
        return;
    }

    const clientId = gate.dataset.googleClientId || "";
    const host = document.getElementById("admin-google-btn-host");
    const statusEl = document.getElementById("admin-auth-status");
    const localForm = document.getElementById("admin-local-login");
    const localUsername = document.getElementById("admin-local-username");
    const localPassword = document.getElementById("admin-local-password");

    function setStatus(msg, isError) {
        if (!statusEl) {
            return;
        }
        statusEl.textContent = msg || "";
        statusEl.classList.toggle("is-error", !!isError);
    }

    async function exchangeCredential(credential) {
        setStatus("Signing in…");
        const res = await fetch("../auth-google.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify({ action: "login", id_token: credential }),
        });
        const data = await res.json().catch(() => null);
        if (!data?.ok) {
            throw new Error(data?.error || "Sign-in failed.");
        }
        window.location.reload();
    }

    async function handleLocalLogin(event) {
        event.preventDefault();
        const username = String(localUsername?.value || "").trim();
        const password = String(localPassword?.value || "");
        if (!username || !password) {
            setStatus("Enter username and password.", true);
            return;
        }
        setStatus("Signing in…");
        try {
            const res = await fetch("../auth-local.php", {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify({ username, password }),
            });
            const data = await res.json().catch(() => null);
            if (!data?.ok) {
                throw new Error(data?.error || "Sign-in failed.");
            }
            window.location.reload();
        } catch (err) {
            setStatus(err?.message || "Sign-in failed.", true);
        }
    }

    localForm?.addEventListener("submit", handleLocalLogin);

    function renderButton() {
        if (!clientId) {
            setStatus("Google client_id is not configured.", true);
            return true;
        }
        if (!window.google?.accounts?.id || !host) {
            return false;
        }
        window.google.accounts.id.initialize({
            client_id: clientId,
            callback: (response) => {
                exchangeCredential(response.credential).catch((err) => {
                    setStatus(err?.message || "Sign-in failed.", true);
                    renderButton();
                });
            },
            auto_select: false,
            cancel_on_tap_outside: true,
        });
        host.innerHTML = "";
        window.google.accounts.id.renderButton(host, {
            theme: "filled_black",
            size: "large",
            text: "signin_with",
            shape: "rectangular",
            width: 280,
        });
        return true;
    }

    let tries = 0;
    const timer = setInterval(() => {
        tries += 1;
        if (renderButton() || tries > 40) {
            clearInterval(timer);
            if (tries > 40 && !host?.childElementCount) {
                setStatus("Google Sign-In failed to load. Refresh and try again.", true);
            }
        }
    }, 150);
})();
