(() => {
    const modal = document.getElementById("incident-modal");
    const form = document.getElementById("incident-form") || document.querySelector(".incident-form");
    const fileInput = document.getElementById("incident-attachment");
    const preview = document.getElementById("incident-paste-preview");
    const previewImg = document.getElementById("incident-paste-preview-img");
    const previewName = document.getElementById("incident-paste-preview-name");
    const clearBtn = document.getElementById("incident-paste-clear");
    const hint = document.getElementById("incident-paste-hint");
    const openButtons = document.querySelectorAll("#incident-open-report, [data-open-report]");
    const closeButtons = document.querySelectorAll("[data-close-report]");

    if (!modal || !form || !fileInput) {
        return;
    }

    const allowedTypes = new Set(["image/jpeg", "image/png", "image/webp", "image/gif"]);
    const maxBytes = 2 * 1024 * 1024;
    let previewUrl = null;
    let lastFocus = null;

    function isModalOpen() {
        return !modal.hidden;
    }

    function openModal() {
        lastFocus = document.activeElement;
        modal.hidden = false;
        document.body.classList.add("incident-modal-open");
        const firstField = form.querySelector("input, select, textarea, button");
        firstField?.focus();
    }

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove("incident-modal-open");
        if (lastFocus && typeof lastFocus.focus === "function") {
            lastFocus.focus();
        }
    }

    openButtons.forEach((btn) => {
        btn.addEventListener("click", (event) => {
            event.preventDefault();
            openModal();
        });
    });

    closeButtons.forEach((btn) => {
        btn.addEventListener("click", (event) => {
            event.preventDefault();
            closeModal();
        });
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && isModalOpen()) {
            closeModal();
        }
    });

    function setHint(message, isError) {
        if (!hint) {
            return;
        }
        hint.textContent = message;
        hint.classList.toggle("is-error", Boolean(isError));
    }

    function clearPreview() {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = null;
        }
        if (preview) {
            preview.hidden = true;
        }
        if (previewImg) {
            previewImg.removeAttribute("src");
        }
        if (previewName) {
            previewName.textContent = "";
        }
    }

    function showPreview(file) {
        clearPreview();
        previewUrl = URL.createObjectURL(file);
        if (previewImg) {
            previewImg.src = previewUrl;
            previewImg.alt = file.name || "Pasted screenshot";
        }
        if (previewName) {
            const kb = Math.max(1, Math.round(file.size / 1024));
            previewName.textContent = `${file.name || "screenshot"} · ${kb} KB`;
        }
        if (preview) {
            preview.hidden = false;
        }
    }

    function assignFile(file, sourceLabel) {
        if (!file || !allowedTypes.has(file.type)) {
            setHint("Paste a JPG, PNG, WEBP, or GIF image.", true);
            return false;
        }
        if (file.size > maxBytes) {
            setHint("Image is over 2 MB. Compress it or pick a smaller file.", true);
            return false;
        }

        const name = file.name && file.name !== "image.png"
            ? file.name
            : `clipboard-${Date.now()}.${file.type.split("/")[1] || "png"}`;
        const named = new File([file], name, { type: file.type, lastModified: Date.now() });

        const transfer = new DataTransfer();
        transfer.items.add(named);
        fileInput.files = transfer.files;

        showPreview(named);
        setHint(`${sourceLabel}: ${named.name}`, false);
        return true;
    }

    function imageFromClipboard(event) {
        const items = event.clipboardData?.items;
        if (!items) {
            return null;
        }
        for (const item of items) {
            if (item.kind === "file" && item.type.startsWith("image/")) {
                return item.getAsFile();
            }
        }
        return null;
    }

    form.addEventListener("paste", (event) => {
        if (!isModalOpen()) {
            return;
        }
        const file = imageFromClipboard(event);
        if (!file) {
            return;
        }
        event.preventDefault();
        assignFile(file, "Pasted from clipboard");
    });

    document.addEventListener("paste", (event) => {
        if (!isModalOpen()) {
            return;
        }
        if (form.contains(document.activeElement)) {
            return;
        }
        const file = imageFromClipboard(event);
        if (!file) {
            return;
        }
        event.preventDefault();
        assignFile(file, "Pasted from clipboard");
    });

    fileInput.addEventListener("change", () => {
        const file = fileInput.files?.[0];
        if (!file) {
            clearPreview();
            setHint("Tip: Ctrl+V / Cmd+V to paste a screenshot.", false);
            return;
        }
        if (!assignFile(file, "Selected file")) {
            fileInput.value = "";
            clearPreview();
        }
    });

    clearBtn?.addEventListener("click", () => {
        fileInput.value = "";
        clearPreview();
        setHint("Tip: Ctrl+V / Cmd+V to paste a screenshot.", false);
    });

    setHint("Tip: Ctrl+V / Cmd+V to paste a screenshot.", false);

    if (document.body.dataset.openReport === "1") {
        openModal();
    }
})();
