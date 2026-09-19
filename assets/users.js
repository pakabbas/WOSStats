const MAX_TEXT = 300;
const RATE_MS = 1200;
const MAX_IMAGE_BYTES = 512 * 1024;
const POLL_MS = 2500;

const els = {
    app: document.getElementById("users-app"),
    authBox: document.getElementById("users-auth-box"),
    signedIn: document.getElementById("users-signed-in"),
    googleHost: document.getElementById("users-google-btn-host"),
    signOutBtn: document.getElementById("users-signout"),
    authStatus: document.getElementById("users-auth-status"),
    mePhoto: document.getElementById("users-me-photo"),
    meName: document.getElementById("users-me-name"),
    meEmail: document.getElementById("users-me-email"),
    search: document.getElementById("users-search"),
    list: document.getElementById("users-list"),
    dmEmpty: document.getElementById("users-dm-empty"),
    dmPanel: document.getElementById("users-dm-panel"),
    dmBack: document.getElementById("users-dm-back"),
    dmPhoto: document.getElementById("users-dm-photo"),
    dmName: document.getElementById("users-dm-name"),
    dmSub: document.getElementById("users-dm-sub"),
    dmMessages: document.getElementById("users-dm-messages"),
    dmStatus: document.getElementById("users-dm-status"),
    dmComposer: document.getElementById("users-dm-composer"),
    dmText: document.getElementById("users-dm-text"),
    dmImageBtn: document.getElementById("users-dm-image-btn"),
    dmImageInput: document.getElementById("users-dm-image-input"),
    dmImagePreview: document.getElementById("users-dm-image-preview"),
    dmImagePreviewImg: document.getElementById("users-dm-image-preview-img"),
    dmImagePreviewName: document.getElementById("users-dm-image-preview-name"),
    dmImageClear: document.getElementById("users-dm-image-clear"),
};

if (els.app) {
    bootUsers();
}

function bootUsers() {
    const clientId = els.app.getAttribute("data-google-client-id") || "";
    let currentUser = null;
    let users = [];
    let searchTerm = "";
    let peer = null;
    let messages = [];
    let lastSentAt = 0;
    let pendingImageFile = null;
    let pendingPreviewUrl = null;
    let pollTimer = null;
    let gsiReady = false;

    if (!clientId) {
        setAuthStatus("Google client_id missing in config.json.", true);
    } else {
        waitForGsi(() => {
            gsiReady = true;
            window.google.accounts.id.initialize({
                client_id: clientId,
                callback: handleGoogleCredential,
                auto_select: false,
                cancel_on_tap_outside: true,
            });
            renderGoogleButton();
        });
    }

    els.signOutBtn?.addEventListener("click", async () => {
        stopPoll();
        closeDm();
        await fetch("auth-google.php", {
            method: "POST",
            headers: { "Content-Type": "application/json", Accept: "application/json" },
            body: JSON.stringify({ action: "logout" }),
        });
        currentUser = null;
        showSignedOut();
        setAuthStatus("");
        renderGoogleButton();
    });

    els.search?.addEventListener("input", () => {
        searchTerm = String(els.search.value || "").trim().toLowerCase();
        renderUserList();
    });

    els.dmBack?.addEventListener("click", () => {
        closeDm();
        document.body.classList.remove("users-dm-open");
    });

    els.dmImageBtn?.addEventListener("click", () => els.dmImageInput?.click());
    els.dmImageInput?.addEventListener("change", () => {
        const file = els.dmImageInput.files && els.dmImageInput.files[0];
        if (file) {
            queueImageFile(file);
        }
        els.dmImageInput.value = "";
    });
    els.dmImageClear?.addEventListener("click", clearPendingImage);
    els.dmComposer?.addEventListener("submit", async (event) => {
        event.preventDefault();
        await sendDm();
    });

    document.addEventListener("paste", (event) => {
        if (!peer || !currentUser) {
            return;
        }
        const file = extractClipboardImage(event.clipboardData);
        if (!file) {
            return;
        }
        event.preventDefault();
        queueImageFile(file);
    });

    refreshSession();

    async function handleGoogleCredential(response) {
        const credential = response?.credential;
        if (!credential) {
            setAuthStatus("Google sign-in returned no credential.", true);
            return;
        }
        setAuthStatus("Signing in…");
        try {
            const res = await fetch("auth-google.php", {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify({ id_token: credential }),
            });
            const data = await res.json();
            if (!data?.ok || !data.user) {
                throw new Error(data?.error || "Sign-in failed.");
            }
            currentUser = data.user;
            showSignedIn(currentUser);
            setAuthStatus("");
            await loadUsers();
        } catch (err) {
            setAuthStatus(err?.message || "Sign-in failed.", true);
        }
    }

    async function refreshSession() {
        try {
            const res = await fetch("users-api.php?action=me", { headers: { Accept: "application/json" } });
            const data = await res.json();
            if (data?.ok && data.user) {
                currentUser = data.user;
                showSignedIn(currentUser);
                await loadUsers();
            } else {
                showSignedOut();
            }
        } catch {
            showSignedOut();
        }
    }

    async function loadUsers() {
        if (!currentUser) {
            return;
        }
        try {
            const res = await fetch("users-api.php?action=list", { headers: { Accept: "application/json" } });
            const data = await res.json();
            if (!data?.ok) {
                throw new Error(data?.error || "Could not load users.");
            }
            users = Array.isArray(data.users) ? data.users : [];
            renderUserList();
        } catch (err) {
            if (els.list) {
                els.list.innerHTML = `<p class="chat-empty">${escapeHtml(err?.message || "Could not load users.")}</p>`;
            }
        }
    }

    function renderUserList() {
        if (!els.list || !currentUser) {
            return;
        }
        const rows = users.filter((u) => {
            if (!searchTerm) {
                return true;
            }
            const hay = `${u.display_name || ""} ${u.email || ""}`.toLowerCase();
            return hay.includes(searchTerm);
        });

        if (rows.length === 0) {
            els.list.innerHTML = `<p class="chat-empty">${searchTerm ? "No matching users." : "No other users yet. Share this page so people can sign in."}</p>`;
            return;
        }

        els.list.innerHTML = rows
            .map((u) => {
                const active = peer && peer.id === u.id ? " is-active" : "";
                const photo = u.photo_url
                    ? `<img class="users-avatar users-avatar-sm" src="${escapeAttr(u.photo_url)}" alt="">`
                    : `<span class="users-avatar users-avatar-sm users-avatar-fallback">${escapeHtml(initials(u.display_name))}</span>`;
                return `<button type="button" class="users-list-item${active}" data-uid="${escapeAttr(u.id)}">
                    ${photo}
                    <span class="users-list-item-meta">
                        <strong>${escapeHtml(u.display_name || "User")}</strong>
                        <span class="muted">${escapeHtml(u.email || "")}</span>
                    </span>
                </button>`;
            })
            .join("");

        els.list.querySelectorAll("[data-uid]").forEach((btn) => {
            btn.addEventListener("click", () => {
                const id = btn.getAttribute("data-uid");
                const user = users.find((row) => row.id === id);
                if (user) {
                    openDm(user);
                }
            });
        });
    }

    async function openDm(user) {
        peer = user;
        document.body.classList.add("users-dm-open");
        if (els.dmEmpty) {
            els.dmEmpty.hidden = true;
        }
        if (els.dmPanel) {
            els.dmPanel.hidden = false;
        }
        if (els.dmName) {
            els.dmName.textContent = user.display_name || "User";
        }
        if (els.dmSub) {
            els.dmSub.textContent = user.email || "Private message";
        }
        if (els.dmPhoto) {
            if (user.photo_url) {
                els.dmPhoto.hidden = false;
                els.dmPhoto.src = user.photo_url;
            } else {
                els.dmPhoto.hidden = true;
                els.dmPhoto.removeAttribute("src");
            }
        }
        renderUserList();
        messages = [];
        await loadMessages(true);
        startPoll();
        setDmStatus("Connected.");
        els.dmText?.focus();
    }

    function closeDm() {
        peer = null;
        stopPoll();
        messages = [];
        if (els.dmPanel) {
            els.dmPanel.hidden = true;
        }
        if (els.dmEmpty) {
            els.dmEmpty.hidden = false;
        }
        clearPendingImage();
        renderUserList();
    }

    function startPoll() {
        stopPoll();
        pollTimer = window.setInterval(() => {
            loadMessages(false).catch(() => {});
        }, POLL_MS);
    }

    function stopPoll() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    async function loadMessages(replace) {
        if (!peer) {
            return;
        }
        const afterId = !replace && messages.length ? messages[messages.length - 1].id : 0;
        const url = afterId
            ? `users-api.php?action=messages&peer=${encodeURIComponent(peer.id)}&after_id=${afterId}`
            : `users-api.php?action=messages&peer=${encodeURIComponent(peer.id)}`;
        const res = await fetch(url, { headers: { Accept: "application/json" } });
        const data = await res.json();
        if (!data?.ok) {
            throw new Error(data?.error || "Could not load messages.");
        }
        const incoming = Array.isArray(data.messages) ? data.messages : [];
        if (replace || afterId === 0) {
            messages = incoming;
        } else if (incoming.length) {
            messages = messages.concat(incoming);
        }
        renderMessages();
    }

    function renderMessages() {
        if (!els.dmMessages) {
            return;
        }
        if (!messages.length) {
            els.dmMessages.innerHTML = `<p class="chat-empty">No messages yet. Say hi.</p>`;
            return;
        }
        const mine = currentUser?.id;
        els.dmMessages.innerHTML = messages
            .map((msg) => {
                const isMine = msg.from_user_id === mine;
                const text = msg.body ? `<p class="chat-message-original">${escapeHtml(msg.body)}</p>` : "";
                const image = msg.image_url
                    ? `<a class="chat-message-image-link" href="${escapeAttr(msg.image_url)}" target="_blank" rel="noopener noreferrer"><img class="chat-message-image" src="${escapeAttr(msg.image_url)}" alt="Shared image" loading="lazy"></a>`
                    : "";
                return `<div class="chat-message${isMine ? " is-mine" : ""}">
                    <div class="chat-message-meta">
                        <strong>${escapeHtml(msg.from_name || "User")}</strong>
                        <time>${escapeHtml(formatTime(msg.created_at))}</time>
                    </div>
                    <div class="chat-message-body">
                        <div class="chat-message-text">
                            ${image}
                            ${text}
                        </div>
                    </div>
                </div>`;
            })
            .join("");
        els.dmMessages.scrollTop = els.dmMessages.scrollHeight;
    }

    async function sendDm() {
        if (!currentUser || !peer) {
            return;
        }
        const text = String(els.dmText?.value || "").trim().slice(0, MAX_TEXT);
        if (!text && !pendingImageFile) {
            return;
        }
        const now = Date.now();
        if (now - lastSentAt < RATE_MS) {
            setDmStatus("Slow down a second…", true);
            return;
        }

        let imageUrl = null;
        if (pendingImageFile) {
            setDmStatus("Uploading image…");
            try {
                imageUrl = await uploadChatImage(pendingImageFile);
            } catch (err) {
                setDmStatus(err?.message || "Image upload failed.", true);
                return;
            }
        }

        try {
            const res = await fetch("users-api.php?action=send", {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify({
                    peer: peer.id,
                    text,
                    image_url: imageUrl,
                }),
            });
            const data = await res.json();
            if (!data?.ok) {
                throw new Error(data?.error || "Send failed.");
            }
            lastSentAt = now;
            if (els.dmText) {
                els.dmText.value = "";
            }
            clearPendingImage();
            if (data.message) {
                messages.push(data.message);
                renderMessages();
            } else {
                await loadMessages(true);
            }
            setDmStatus("Sent.");
        } catch (err) {
            setDmStatus(err?.message || "Send failed.", true);
        }
    }

    function queueImageFile(file) {
        if (!file || !file.type.startsWith("image/")) {
            setDmStatus("Only image files are allowed.", true);
            return;
        }
        if (file.size > MAX_IMAGE_BYTES) {
            setDmStatus("Image must be 512 KB or smaller.", true);
            return;
        }
        clearPendingImage();
        pendingImageFile = file;
        pendingPreviewUrl = URL.createObjectURL(file);
        if (els.dmImagePreview) {
            els.dmImagePreview.hidden = false;
        }
        if (els.dmImagePreviewImg) {
            els.dmImagePreviewImg.src = pendingPreviewUrl;
        }
        if (els.dmImagePreviewName) {
            els.dmImagePreviewName.textContent = `${file.name || "image"} · ${Math.round(file.size / 1024)} KB`;
        }
    }

    function clearPendingImage() {
        pendingImageFile = null;
        if (pendingPreviewUrl) {
            URL.revokeObjectURL(pendingPreviewUrl);
            pendingPreviewUrl = null;
        }
        if (els.dmImagePreview) {
            els.dmImagePreview.hidden = true;
        }
        if (els.dmImagePreviewImg) {
            els.dmImagePreviewImg.removeAttribute("src");
        }
        if (els.dmImagePreviewName) {
            els.dmImagePreviewName.textContent = "";
        }
    }

    function renderGoogleButton() {
        if (
            !els.googleHost ||
            !gsiReady ||
            !window.google?.accounts?.id ||
            currentUser ||
            document.body.classList.contains("users-is-signed-in")
        ) {
            return;
        }
        els.googleHost.innerHTML = "";
        window.google.accounts.id.renderButton(els.googleHost, {
            theme: "filled_blue",
            size: "large",
            shape: "rectangular",
            text: "signin_with",
            width: Math.min(320, Math.max(240, els.googleHost.clientWidth || 280)),
        });
    }

    function showSignedOut() {
        document.body.classList.remove("users-is-signed-in");
        if (els.authBox) {
            els.authBox.hidden = false;
        }
        if (els.signedIn) {
            els.signedIn.hidden = true;
        }
        if (els.signOutBtn) {
            els.signOutBtn.hidden = true;
        }
        if (els.googleHost) {
            els.googleHost.innerHTML = "";
        }
        if (els.list) {
            els.list.innerHTML = `<p class="chat-empty">Sign in to see users.</p>`;
        }
    }

    function showSignedIn(user) {
        document.body.classList.add("users-is-signed-in");
        if (els.authBox) {
            els.authBox.hidden = true;
        }
        if (els.signedIn) {
            els.signedIn.hidden = false;
        }
        if (els.signOutBtn) {
            els.signOutBtn.hidden = false;
        }
        if (els.googleHost) {
            els.googleHost.innerHTML = "";
        }
        if (els.meName) {
            els.meName.textContent = user.display_name || "User";
        }
        if (els.meEmail) {
            els.meEmail.textContent = user.email || "";
        }
        if (els.mePhoto) {
            if (user.photo_url) {
                els.mePhoto.hidden = false;
                els.mePhoto.src = user.photo_url;
            } else {
                els.mePhoto.hidden = true;
                els.mePhoto.removeAttribute("src");
            }
        }
    }
}

function waitForGsi(cb, tries = 0) {
    if (window.google?.accounts?.id) {
        cb();
        return;
    }
    if (tries > 80) {
        setAuthStatus("Google Sign-In script failed to load.", true);
        return;
    }
    window.setTimeout(() => waitForGsi(cb, tries + 1), 100);
}

function initials(name) {
    const parts = String(name || "U").trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) {
        return "U";
    }
    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }
    return (parts[0][0] + parts[1][0]).toUpperCase();
}

function formatTime(value) {
    if (!value) {
        return "";
    }
    try {
        const d = value.includes("T") ? new Date(value) : new Date(String(value).replace(" ", "T") + "Z");
        return d.toLocaleString(undefined, {
            month: "short",
            day: "numeric",
            hour: "2-digit",
            minute: "2-digit",
        });
    } catch {
        return String(value);
    }
}

function extractClipboardImage(clipboardData) {
    if (!clipboardData?.items) {
        return null;
    }
    for (const item of clipboardData.items) {
        if (item.type && item.type.startsWith("image/")) {
            return item.getAsFile();
        }
    }
    return null;
}

async function uploadChatImage(file) {
    const body = new FormData();
    body.append("image", file, file.name || "chat.jpg");
    const res = await fetch("chat-upload.php", { method: "POST", body });
    const data = await res.json();
    if (!data?.ok || !data.url) {
        throw new Error(data?.error || "Upload failed.");
    }
    if (String(data.url).startsWith("http")) {
        return data.url;
    }
    return new URL(data.url, window.location.href).href;
}

function setAuthStatus(message, isError = false) {
    if (!els.authStatus) {
        return;
    }
    els.authStatus.textContent = message || "";
    els.authStatus.classList.toggle("is-error", Boolean(isError && message));
}

function setDmStatus(message, isError = false) {
    if (!els.dmStatus) {
        return;
    }
    els.dmStatus.textContent = message || "";
    els.dmStatus.classList.toggle("is-error", Boolean(isError && message));
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}

function escapeAttr(value) {
    return escapeHtml(value).replace(/'/g, "&#39;");
}
