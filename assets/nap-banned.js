(() => {
    const addModal = document.getElementById("nap-banned-add-modal");
    const removeModal = document.getElementById("nap-banned-remove-modal");
    const addForm = document.getElementById("nap-banned-add-form");
    const removeForm = document.getElementById("nap-banned-remove-form");
    const removeEntryIdInput = document.getElementById("nap-banned-remove-entry-id");
    const removeTargetEl = document.getElementById("nap-banned-remove-target");
    const removePasswordInput = document.getElementById("nap-banned-remove-password");

    const idInput = document.getElementById("nap-banned-id");
    const nameInput = document.getElementById("nap-banned-name");
    const lookupBtn = document.getElementById("nap-banned-lookup");
    const statusEl = document.getElementById("nap-banned-lookup-status");
    const previewEl = document.getElementById("nap-banned-lookup-preview");

    let lastFocus = null;

    function openModal(modal) {
        if (!modal) {
            return;
        }
        lastFocus = document.activeElement;
        modal.hidden = false;
        document.body.classList.add("nap-banned-modal-open");
        const firstField = modal.querySelector("input:not([type='hidden']), select, textarea, button");
        firstField?.focus();
    }

    function closeModal(modal) {
        if (!modal) {
            return;
        }
        modal.hidden = true;
        if (
            (!addModal || addModal.hidden) &&
            (!removeModal || removeModal.hidden)
        ) {
            document.body.classList.remove("nap-banned-modal-open");
        }
        if (lastFocus && typeof lastFocus.focus === "function") {
            lastFocus.focus();
        }
    }

    document.querySelectorAll("[data-open-add]").forEach((btn) => {
        btn.addEventListener("click", (event) => {
            event.preventDefault();
            openModal(addModal);
        });
    });

    document.querySelectorAll("[data-close-add]").forEach((btn) => {
        btn.addEventListener("click", (event) => {
            event.preventDefault();
            closeModal(addModal);
        });
    });

    document.querySelectorAll("[data-open-remove]").forEach((btn) => {
        btn.addEventListener("click", (event) => {
            event.preventDefault();
            const entryId = btn.getAttribute("data-entry-id") || "";
            const entryName = btn.getAttribute("data-entry-name") || "this player";
            if (removeEntryIdInput) {
                removeEntryIdInput.value = entryId;
            }
            if (removeTargetEl) {
                removeTargetEl.textContent = `Remove ${entryName} from the NAP banned list?`;
            }
            if (removePasswordInput) {
                removePasswordInput.value = "";
            }
            openModal(removeModal);
        });
    });

    document.querySelectorAll("[data-close-remove]").forEach((btn) => {
        btn.addEventListener("click", (event) => {
            event.preventDefault();
            closeModal(removeModal);
        });
    });

    document.addEventListener("keydown", (event) => {
        if (event.key !== "Escape") {
            return;
        }
        if (removeModal && !removeModal.hidden) {
            closeModal(removeModal);
            return;
        }
        if (addModal && !addModal.hidden) {
            closeModal(addModal);
        }
    });

    // Keep remove target text if modal reopened after failed POST.
    if (removeModal && !removeModal.hidden && removeEntryIdInput?.value) {
        const rowBtn = document.querySelector(
            `[data-open-remove][data-entry-id="${CSS.escape(removeEntryIdInput.value)}"]`
        );
        const entryName = rowBtn?.getAttribute("data-entry-name") || "this player";
        if (removeTargetEl) {
            removeTargetEl.textContent = `Remove ${entryName} from the NAP banned list?`;
        }
    }

    if (!lookupBtn || !idInput) {
        return;
    }

    function setStatus(message, isError) {
        if (!statusEl) {
            return;
        }
        if (!message) {
            statusEl.hidden = true;
            statusEl.textContent = "";
            statusEl.classList.remove("is-error");
            return;
        }
        statusEl.hidden = false;
        statusEl.textContent = message;
        statusEl.classList.toggle("is-error", Boolean(isError));
    }

    function showPreview(player) {
        if (!previewEl) {
            return;
        }
        const bits = [];
        if (player.name) {
            bits.push(`<strong>${escapeHtml(player.name)}</strong>`);
        }
        if (player.id) {
            bits.push(`UID ${escapeHtml(String(player.id))}`);
        }
        if (player.fid) {
            bits.push(`FID ${escapeHtml(String(player.fid))}`);
        }
        if (player.kid) {
            bits.push(`State ${escapeHtml(String(player.kid))}`);
        }
        if (player.alliance_tag) {
            bits.push(escapeHtml(String(player.alliance_tag)));
        }
        if (player.power != null) {
            bits.push(`Power ${formatPower(player.power)}`);
        }
        if (player.x != null && player.y != null) {
            bits.push(`Coords ${player.x}, ${player.y}`);
        }
        previewEl.hidden = false;
        previewEl.innerHTML = bits.join(" · ");
    }

    function clearPreview() {
        if (!previewEl) {
            return;
        }
        previewEl.hidden = true;
        previewEl.innerHTML = "";
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function formatPower(power) {
        const n = Number(power);
        if (!Number.isFinite(n)) {
            return "—";
        }
        if (n >= 1e9) {
            return (n / 1e9).toFixed(1).replace(/\.0$/, "") + "B";
        }
        if (n >= 1e6) {
            return (n / 1e6).toFixed(1).replace(/\.0$/, "") + "M";
        }
        if (n >= 1e3) {
            return (n / 1e3).toFixed(1).replace(/\.0$/, "") + "K";
        }
        return String(n);
    }

    async function lookupPlayer() {
        const uid = String(idInput.value || "").replace(/\D+/g, "");
        if (uid.length < 5) {
            setStatus("Enter a valid player ID to look up.", true);
            clearPreview();
            return;
        }

        setStatus("Looking up…", false);
        clearPreview();
        lookupBtn.disabled = true;

        try {
            const res = await fetch(`player-info.php?uid=${encodeURIComponent(uid)}`, {
                headers: { Accept: "application/json" },
            });
            const data = await res.json();
            if (!data?.ok || !data.player) {
                setStatus(data?.error || "Player not found.", true);
                return;
            }

            const player = data.player;
            if (player.name && nameInput) {
                nameInput.value = player.name;
            }
            if (player.id) {
                idInput.value = String(player.id);
            }

            showPreview(player);
            setStatus("Player found — review and add with password.", false);
        } catch (e) {
            setStatus("Lookup failed. You can still add by name only.", true);
            clearPreview();
        } finally {
            lookupBtn.disabled = false;
        }
    }

    lookupBtn.addEventListener("click", lookupPlayer);
    idInput.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
            event.preventDefault();
            lookupPlayer();
        }
    });
})();
