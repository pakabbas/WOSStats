import { initializeApp } from "https://www.gstatic.com/firebasejs/12.19.0/firebase-app.js";
import {
    getDatabase,
    ref,
    push,
    query,
    limitToLast,
    onValue,
} from "https://www.gstatic.com/firebasejs/12.19.0/firebase-database.js";
import { firebaseConfig } from "./firebase-config.js";

const NICK_KEY = "wos_chat_nick";
const LANG_KEY = "wos_chat_lang";
const MAX_TEXT = 300;
const MAX_NICK = 20;
const MIN_NICK = 2;
const RATE_MS = 1500;
const HISTORY_LIMIT = 120;
const MAX_IMAGE_BYTES = 512 * 1024;

const els = {
    root: document.getElementById("live-chat"),
    messages: document.getElementById("chat-messages"),
    status: document.getElementById("chat-status"),
    setup: document.getElementById("chat-setup"),
    nickGate: document.getElementById("chat-nick-gate"),
    nickInput: document.getElementById("chat-nick-input"),
    nickSave: document.getElementById("chat-nick-save"),
    composer: document.getElementById("chat-composer"),
    textInput: document.getElementById("chat-text-input"),
    send: document.getElementById("chat-send"),
    nickLabel: document.getElementById("chat-nick-label"),
    changeNick: document.getElementById("chat-change-nick"),
    emojiToggle: document.getElementById("chat-emoji-toggle"),
    emojiPanel: document.getElementById("chat-emoji-panel"),
    langSelect: document.getElementById("chat-lang-select"),
    langSearch: document.getElementById("chat-lang-search"),
    langOptions: document.getElementById("chat-lang-options"),
    langCombo: document.getElementById("chat-lang-combo"),
    langBar: document.getElementById("chat-lang-bar"),
    imageBtn: document.getElementById("chat-image-btn"),
    imageInput: document.getElementById("chat-image-input"),
    imagePreview: document.getElementById("chat-image-preview"),
    imagePreviewImg: document.getElementById("chat-image-preview-img"),
    imagePreviewName: document.getElementById("chat-image-preview-name"),
    imageClear: document.getElementById("chat-image-clear"),
};

const translateCache = new Map();

if (els.root) {
    bootChat();
}

function bootChat() {
    let nick = sanitizeNick(localStorage.getItem(NICK_KEY) || "");
    let chatLang = sanitizeLang(getCookie(LANG_KEY) || "");
    if (!chatLang) {
        // One-time migrate from older localStorage preference.
        chatLang = sanitizeLang(localStorage.getItem(LANG_KEY) || "");
        if (chatLang) {
            setCookie(LANG_KEY, chatLang, 365);
            localStorage.removeItem(LANG_KEY);
        }
    }
    let lastSentAt = 0;
    let dbReady = false;
    let messagesRef = null;
    let pendingImageFile = null;
    let pendingPreviewUrl = null;

    if (els.langSelect || els.langSearch) {
        initLangCombo(chatLang, (next) => {
            chatLang = next;
        });
    }

    els.nickSave?.addEventListener("click", () => {
        const next = sanitizeNick(els.nickInput.value);
        if (!next) {
            setStatus(`Nick must be ${MIN_NICK}–${MAX_NICK} characters.`, true);
            return;
        }
        nick = next;
        localStorage.setItem(NICK_KEY, nick);
        showComposer();
        setStatus(dbReady ? "Connected." : "Connecting…");
        els.textInput?.focus();
    });

    els.nickInput?.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
            event.preventDefault();
            els.nickSave?.click();
        }
    });

    els.changeNick?.addEventListener("click", () => {
        showNickGate();
        els.nickInput.value = nick;
        els.nickInput?.focus();
    });

    els.emojiToggle?.addEventListener("click", () => {
        if (!els.emojiPanel) {
            return;
        }
        els.emojiPanel.hidden = !els.emojiPanel.hidden;
    });

    els.emojiPanel?.addEventListener("click", (event) => {
        const button = event.target.closest("[data-emoji]");
        if (!button || !els.textInput) {
            return;
        }
        insertAtCursor(els.textInput, button.dataset.emoji || "");
        els.emojiPanel.hidden = true;
        els.textInput.focus();
    });

    els.imageBtn?.addEventListener("click", () => {
        els.imageInput?.click();
    });

    els.imageInput?.addEventListener("change", () => {
        const file = els.imageInput.files && els.imageInput.files[0];
        if (!file) {
            return;
        }
        queueImageFile(file);
        els.imageInput.value = "";
    });

    els.imageClear?.addEventListener("click", () => {
        clearPendingImage();
    });

    function extractClipboardImage(clipboardData) {
        if (!clipboardData) {
            return null;
        }
        const items = clipboardData.items;
        if (items) {
            for (const item of items) {
                if (item.type && item.type.startsWith("image/")) {
                    return item.getAsFile();
                }
            }
        }
        const files = clipboardData.files;
        if (files && files.length) {
            for (const file of files) {
                if (file && String(file.type || "").startsWith("image/")) {
                    return file;
                }
            }
        }
        return null;
    }

    function handlePasteImage(event) {
        if (els.composer?.hidden) {
            return;
        }
        const file = extractClipboardImage(event.clipboardData);
        if (!file) {
            return;
        }
        event.preventDefault();
        queueImageFile(file);
        els.textInput?.focus();
    }

    // Broad paste target: many browsers skip image clipboard data on <input type="text">.
    document.addEventListener("paste", handlePasteImage);

    els.composer?.addEventListener("dragover", (event) => {
        if (els.composer?.hidden) {
            return;
        }
        event.preventDefault();
        els.composer.classList.add("is-drop-target");
    });
    els.composer?.addEventListener("dragleave", () => {
        els.composer?.classList.remove("is-drop-target");
    });
    els.composer?.addEventListener("drop", (event) => {
        els.composer?.classList.remove("is-drop-target");
        if (els.composer?.hidden) {
            return;
        }
        const file = event.dataTransfer?.files?.[0];
        if (!file || !String(file.type || "").startsWith("image/")) {
            return;
        }
        event.preventDefault();
        queueImageFile(file);
    });

    function queueImageFile(file) {
        if (!(file instanceof Blob)) {
            setStatus("Invalid image.", true);
            return;
        }
        if (!String(file.type || "").startsWith("image/")) {
            setStatus("Only image files are allowed.", true);
            return;
        }
        if (file.size <= 0 || file.size > MAX_IMAGE_BYTES) {
            setStatus("Image must be 512 KB or smaller.", true);
            return;
        }
        clearPendingImage();
        pendingImageFile = file;
        pendingPreviewUrl = URL.createObjectURL(file);
        if (els.imagePreviewImg) {
            els.imagePreviewImg.src = pendingPreviewUrl;
        }
        if (els.imagePreviewName) {
            const kb = Math.max(1, Math.round(file.size / 1024));
            els.imagePreviewName.textContent = `${file.name || "pasted-image"} · ${kb} KB`;
        }
        if (els.imagePreview) {
            els.imagePreview.hidden = false;
        }
        setStatus("Image ready — add text or send.");
    }

    function clearPendingImage() {
        if (pendingPreviewUrl) {
            URL.revokeObjectURL(pendingPreviewUrl);
            pendingPreviewUrl = null;
        }
        pendingImageFile = null;
        if (els.imagePreview) {
            els.imagePreview.hidden = true;
        }
        if (els.imagePreviewImg) {
            els.imagePreviewImg.removeAttribute("src");
        }
        if (els.imagePreviewName) {
            els.imagePreviewName.textContent = "";
        }
        if (els.imageInput) {
            els.imageInput.value = "";
        }
    }

    async function uploadChatImage(file) {
        const body = new FormData();
        body.append("image", file, file.name || "paste.jpg");
        const res = await fetch("chat-upload.php", {
            method: "POST",
            body,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data?.ok || !data.url) {
            throw new Error(data?.error || "Image upload failed.");
        }
        return String(data.url);
    }

    els.messages?.addEventListener("click", async (event) => {
        const button = event.target.closest("[data-translate-btn]");
        if (!button) {
            return;
        }
        const row = button.closest(".chat-message");
        if (!row) {
            return;
        }
        await toggleTranslate(row, button, () => chatLang);
    });

    els.composer?.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (!nick) {
            setStatus("Choose a nick first.", true);
            return;
        }
        if (!dbReady || !messagesRef) {
            setStatus("Not connected to Firebase yet.", true);
            showSetup(true);
            return;
        }

        const text = sanitizeText(els.textInput?.value || "");
        const imageFile = pendingImageFile;
        if (!text && !imageFile) {
            setStatus("Type a message or attach an image.", true);
            return;
        }

        const now = Date.now();
        if (now - lastSentAt < RATE_MS) {
            setStatus("Slow down — wait a second before sending again.", true);
            return;
        }

        const previousText = text;
        const previousImage = imageFile;
        if (els.textInput) {
            els.textInput.value = "";
        }
        clearPendingImage();
        lastSentAt = now;
        els.send.disabled = true;
        setStatus(imageFile ? "Uploading image…" : "Sending…");

        try {
            let imageUrl = null;
            if (imageFile) {
                imageUrl = await uploadChatImage(imageFile);
                setStatus("Sending…");
            }

            const payload = {
                nick,
                createdAt: Date.now(),
            };
            if (text) {
                payload.text = text;
            }
            if (imageUrl) {
                payload.imageUrl = imageUrl;
            }

            const result = await push(messagesRef, payload);
            if (!result?.key) {
                throw new Error("Message was not saved.");
            }
            setStatus("Sent.");
            setTimeout(() => {
                if (els.status?.textContent === "Sent.") {
                    setStatus("");
                }
            }, 1200);
        } catch (error) {
            console.error(error);
            if (els.textInput) {
                els.textInput.value = previousText;
            }
            if (previousImage) {
                queueImageFile(previousImage);
            }
            lastSentAt = 0;
            setStatus(error?.message || explainFirebaseError(error), true);
            if (!String(error?.message || "").includes("512") && !String(error?.message || "").includes("Image")) {
                showSetup(true);
            }
        } finally {
            els.send.disabled = false;
            els.textInput?.focus();
        }
    });

    if (nick) {
        showComposer();
    } else {
        showNickGate();
    }

    connectFirebase();

    function connectFirebase() {
        try {
            if (!firebaseConfig.databaseURL) {
                throw new Error("databaseURL is missing in firebase-config.js");
            }

            const app = initializeApp(firebaseConfig);
            const db = getDatabase(app);
            messagesRef = ref(db, "chat/messages");

            const recent = query(messagesRef, limitToLast(HISTORY_LIMIT));
            onValue(recent, (snapshot) => {
                renderMessageList(snapshot);
                dbReady = true;
                showSetup(false);
                if (!els.status?.classList.contains("is-error")) {
                    setStatus(nick ? "Live." : "Enter a nick to start chatting.");
                }
            }, (error) => {
                console.error(error);
                dbReady = false;
                setStatus(explainFirebaseError(error), true);
                showSetup(true);
            });

            dbReady = true;
            showSetup(false);
            (nick ? els.textInput : els.nickInput)?.focus();
        } catch (error) {
            console.error(error);
            dbReady = false;
            messagesRef = null;
            setStatus(explainFirebaseError(error), true);
            showSetup(true);
        }
    }

    function showNickGate() {
        els.nickGate.hidden = false;
        els.composer.hidden = true;
        if (els.emojiPanel) {
            els.emojiPanel.hidden = true;
        }
    }

    function showComposer() {
        els.nickGate.hidden = true;
        els.composer.hidden = false;
        if (els.nickLabel) {
            els.nickLabel.textContent = nick;
        }
    }
}

function updateLangUi(lang) {
    els.langBar?.classList.toggle("needs-lang", !lang);
}

function initLangCombo(initialLang, onChange) {
    const options = Array.from(els.langOptions?.querySelectorAll("[data-value]") || []);
    let activeIndex = -1;
    let open = false;

    function optionLabel(el) {
        return String(el?.textContent || "").trim();
    }

    function findOption(value) {
        return options.find((el) => el.dataset.value === value) || null;
    }

    function currentValue() {
        return sanitizeLang(els.langSelect?.value || "");
    }

    function syncSearchLabel(value = currentValue()) {
        if (!els.langSearch) {
            return;
        }
        const match = findOption(value);
        els.langSearch.value = match && value ? optionLabel(match) : "";
    }

    function setLang(value, { silent = false } = {}) {
        const next = sanitizeLang(value);
        if (els.langSelect) {
            els.langSelect.value = next;
        }
        if (next) {
            setCookie(LANG_KEY, next, 365);
        } else {
            deleteCookie(LANG_KEY);
        }
        syncSearchLabel(next);
        updateLangUi(next);
        options.forEach((el) => {
            const selected = el.dataset.value === next;
            el.setAttribute("aria-selected", selected ? "true" : "false");
            el.classList.toggle("is-selected", selected);
        });
        if (typeof onChange === "function") {
            onChange(next);
        }
        if (!silent) {
            resetVisibleTranslations();
        }
    }

    function filterOptions(query) {
        const q = String(query || "").trim().toLowerCase();
        let visibleCount = 0;
        options.forEach((el) => {
            const hay = `${el.dataset.value || ""} ${el.dataset.label || ""} ${optionLabel(el)}`.toLowerCase();
            const show = !q || hay.includes(q);
            el.hidden = !show;
            if (show) {
                visibleCount += 1;
            }
        });
        return visibleCount;
    }

    function visibleOptions() {
        return options.filter((el) => !el.hidden);
    }

    function setOpen(nextOpen) {
        open = nextOpen;
        if (els.langOptions) {
            els.langOptions.hidden = !open;
        }
        els.langSearch?.setAttribute("aria-expanded", open ? "true" : "false");
        els.langCombo?.classList.toggle("is-open", open);
        if (open) {
            const selected = findOption(currentValue());
            const vis = visibleOptions();
            activeIndex = selected && !selected.hidden ? vis.indexOf(selected) : (vis.length ? 0 : -1);
            highlightActive();
        } else {
            activeIndex = -1;
            highlightActive();
        }
    }

    function highlightActive() {
        const vis = visibleOptions();
        options.forEach((el) => el.classList.remove("is-active"));
        if (activeIndex >= 0 && activeIndex < vis.length) {
            vis[activeIndex].classList.add("is-active");
            vis[activeIndex].scrollIntoView({ block: "nearest" });
        }
    }

    function pickOption(el) {
        if (!el) {
            return;
        }
        setLang(el.dataset.value || "");
        setOpen(false);
        els.langSearch?.blur();
    }

    setLang(initialLang, { silent: true });

    els.langSearch?.addEventListener("focus", () => {
        if (els.langSearch) {
            els.langSearch.value = "";
        }
        filterOptions("");
        setOpen(true);
    });

    els.langSearch?.addEventListener("input", () => {
        filterOptions(els.langSearch.value);
        setOpen(true);
        const vis = visibleOptions();
        activeIndex = vis.length ? 0 : -1;
        highlightActive();
    });

    els.langSearch?.addEventListener("keydown", (event) => {
        const vis = visibleOptions();
        if (event.key === "ArrowDown") {
            event.preventDefault();
            if (!open) {
                filterOptions(els.langSearch?.value || "");
                setOpen(true);
                return;
            }
            if (!vis.length) {
                return;
            }
            activeIndex = (activeIndex + 1) % vis.length;
            highlightActive();
        } else if (event.key === "ArrowUp") {
            event.preventDefault();
            if (!open) {
                filterOptions(els.langSearch?.value || "");
                setOpen(true);
                return;
            }
            if (!vis.length) {
                return;
            }
            activeIndex = (activeIndex - 1 + vis.length) % vis.length;
            highlightActive();
        } else if (event.key === "Enter") {
            if (open && activeIndex >= 0 && activeIndex < vis.length) {
                event.preventDefault();
                pickOption(vis[activeIndex]);
            }
        } else if (event.key === "Escape") {
            event.preventDefault();
            setOpen(false);
            syncSearchLabel();
        }
    });

    els.langOptions?.addEventListener("mousedown", (event) => {
        const option = event.target.closest("[data-value]");
        if (!option) {
            return;
        }
        event.preventDefault();
        pickOption(option);
    });

    document.addEventListener("click", (event) => {
        if (!els.langCombo?.contains(event.target)) {
            setOpen(false);
            syncSearchLabel();
        }
    });
}

function getCookie(name) {
    const prefix = `${encodeURIComponent(name)}=`;
    const parts = document.cookie.split(";");
    for (const part of parts) {
        const trimmed = part.trim();
        if (trimmed.startsWith(prefix)) {
            return decodeURIComponent(trimmed.slice(prefix.length));
        }
    }
    return "";
}

function setCookie(name, value, days) {
    const maxAge = Math.max(1, Math.floor(Number(days) || 365)) * 24 * 60 * 60;
    document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(value)}; Max-Age=${maxAge}; Path=/; SameSite=Lax`;
}

function deleteCookie(name) {
    document.cookie = `${encodeURIComponent(name)}=; Max-Age=0; Path=/; SameSite=Lax`;
}

function resetVisibleTranslations() {
    els.messages?.querySelectorAll(".chat-message.is-translated").forEach((row) => {
        const translated = row.querySelector(".chat-message-translated");
        const button = row.querySelector("[data-translate-btn]");
        if (translated) {
            translated.hidden = true;
            translated.textContent = "";
        }
        row.classList.remove("is-translated", "is-translating");
        if (button) {
            button.setAttribute("aria-pressed", "false");
            button.title = "Translate";
        }
    });
}

async function toggleTranslate(row, button, getLang) {
    const original = row.querySelector(".chat-message-original");
    const translated = row.querySelector(".chat-message-translated");
    if (!original || !translated) {
        return;
    }

    if (row.classList.contains("is-translated")) {
        translated.hidden = true;
        row.classList.remove("is-translated");
        button.setAttribute("aria-pressed", "false");
        button.title = "Translate";
        return;
    }

    const lang = sanitizeLang(getLang());
    if (!lang) {
        setStatus("Choose your language at the top first.", true);
        els.langSearch?.focus();
        els.langBar?.classList.add("needs-lang");
        return;
    }

    const text = original.textContent || "";
    if (!text.trim()) {
        return;
    }

    const cacheKey = `${lang}::${text}`;
    row.classList.add("is-translating");
    button.disabled = true;
    button.title = "Translating…";

    try {
        let resultText = translateCache.get(cacheKey);
        if (!resultText) {
            resultText = await fetchTranslation(text, lang);
            translateCache.set(cacheKey, resultText);
        }
        translated.textContent = resultText;
        translated.hidden = false;
        row.classList.add("is-translated");
        button.setAttribute("aria-pressed", "true");
        button.title = "Show original only";
        setStatus("");
    } catch (error) {
        console.error(error);
        setStatus(error?.message || "Translation failed.", true);
    } finally {
        row.classList.remove("is-translating");
        button.disabled = false;
        if (!row.classList.contains("is-translated")) {
            button.title = "Translate";
        }
    }
}

async function fetchTranslation(text, lang) {
    const url = `translate.php?${new URLSearchParams({ q: text, tl: lang })}`;
    const response = await fetch(url, {
        method: "GET",
        headers: { Accept: "application/json" },
        cache: "no-store",
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok || !data.translated) {
        throw new Error(data?.error || "Translation failed.");
    }
    return String(data.translated);
}

function showSetup(visible) {
    if (!els.setup) {
        return;
    }
    els.setup.hidden = !visible;
}

function renderMessageList(snapshot) {
    if (!els.messages) {
        return;
    }

    els.messages.innerHTML = "";

    if (!snapshot.exists()) {
        const empty = document.createElement("p");
        empty.className = "chat-empty";
        empty.textContent = "No messages yet. Be the first.";
        els.messages.append(empty);
        return;
    }

    const rows = [];
    snapshot.forEach((child) => {
        rows.push({ key: child.key, value: child.val() });
    });

    rows.sort((a, b) => {
        const aTime = Number(a.value?.createdAt) || 0;
        const bTime = Number(b.value?.createdAt) || 0;
        return aTime - bTime;
    });

    rows.forEach((row) => {
        if (!row.value || typeof row.value !== "object") {
            return;
        }
        appendMessage(row.value, row.key);
    });

    els.messages.scrollTop = els.messages.scrollHeight;
}

function appendMessage(value, key) {
    if (!els.messages) {
        return;
    }
    if (els.messages.querySelector(`[data-id="${cssEscape(key)}"]`)) {
        return;
    }

    els.messages.querySelector(".chat-empty")?.remove();

    const nick = sanitizeNick(String(value.nick || "")) || "Anon";
    const text = sanitizeText(String(value.text || ""));
    const imageUrl = sanitizeImageUrl(String(value.imageUrl || value.image || ""));
    if (!text && !imageUrl) {
        return;
    }

    const row = document.createElement("div");
    row.className = "chat-message";
    row.dataset.id = key;

    const meta = document.createElement("div");
    meta.className = "chat-message-meta";

    const nameEl = document.createElement("strong");
    nameEl.textContent = nick;

    const timeEl = document.createElement("time");
    timeEl.textContent = formatTime(value.createdAt);

    meta.append(nameEl, timeEl);

    const body = document.createElement("div");
    body.className = "chat-message-body";

    const textWrap = document.createElement("div");
    textWrap.className = "chat-message-text";

    if (imageUrl) {
        const imgLink = document.createElement("a");
        imgLink.href = imageUrl;
        imgLink.target = "_blank";
        imgLink.rel = "noopener noreferrer";
        imgLink.className = "chat-message-image-link";

        const img = document.createElement("img");
        img.className = "chat-message-image";
        img.src = imageUrl;
        img.alt = "Chat image";
        img.loading = "lazy";
        imgLink.append(img);
        textWrap.append(imgLink);
    }

    if (text) {
        const translateBtn = document.createElement("button");
        translateBtn.type = "button";
        translateBtn.className = "chat-translate-btn";
        translateBtn.dataset.translateBtn = "1";
        translateBtn.setAttribute("aria-label", "Translate message");
        translateBtn.setAttribute("aria-pressed", "false");
        translateBtn.title = "Translate";
        translateBtn.textContent = "⇄";

        const original = document.createElement("p");
        original.className = "chat-message-original";
        original.textContent = text;

        const translated = document.createElement("p");
        translated.className = "chat-message-translated";
        translated.hidden = true;

        textWrap.append(original, translated);
        body.append(textWrap, translateBtn);
    } else {
        body.append(textWrap);
    }

    row.append(meta, body);
    els.messages.append(row);

    if (els.messages.childElementCount > HISTORY_LIMIT) {
        els.messages.firstElementChild?.remove();
    }
}

function insertAtCursor(input, text) {
    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? input.value.length;
    const next = `${input.value.slice(0, start)}${text}${input.value.slice(end)}`.slice(0, MAX_TEXT);
    input.value = next;
    const caret = Math.min(start + text.length, next.length);
    input.setSelectionRange(caret, caret);
}

function sanitizeNick(value) {
    const nick = String(value || "")
        .replace(/[<>]/g, "")
        .replace(/\s+/g, " ")
        .trim()
        .slice(0, MAX_NICK);

    return nick.length >= MIN_NICK ? nick : "";
}

function sanitizeText(value) {
    return String(value || "")
        .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, "")
        .replace(/\s+/g, " ")
        .trim()
        .slice(0, MAX_TEXT);
}

function sanitizeImageUrl(value) {
    const raw = String(value || "").trim();
    if (!raw || raw.length > 2000) {
        return "";
    }
    // Relative path (legacy / local)
    if (/^uploads\/chat\/[a-zA-Z0-9._-]+\.(jpe?g|png|webp|gif)$/i.test(raw)) {
        return raw;
    }
    try {
        const url = new URL(raw);
        if (url.protocol !== "https:") {
            return "";
        }
        if (!/\/uploads\/chat\/[a-zA-Z0-9._-]+\.(jpe?g|png|webp|gif)$/i.test(url.pathname)) {
            return "";
        }
        return url.href;
    } catch {
        return "";
    }
}

function sanitizeLang(value) {
    const lang = String(value || "").trim().toLowerCase();
    const allowed = new Set([
        "en", "ar", "zh-cn", "zh-tw", "de", "es", "fr", "hi", "id", "it",
        "ja", "ko", "ms", "nl", "pl", "pt", "ru", "th", "tr", "uk", "ur", "vi",
    ]);
    return allowed.has(lang) ? lang : "";
}

function formatTime(createdAt) {
    if (typeof createdAt !== "number" || !Number.isFinite(createdAt)) {
        return "now";
    }
    try {
        return new Date(createdAt).toLocaleString("en-GB", {
            timeZone: "UTC",
            day: "2-digit",
            month: "short",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
            hour12: false,
        }) + " UTC";
    } catch {
        return "";
    }
}

function setStatus(message, isError = false) {
    if (!els.status) {
        return;
    }
    els.status.textContent = message || "";
    els.status.classList.toggle("is-error", Boolean(isError && message));
}

function explainFirebaseError(error) {
    const code = String(error?.code || "");
    const message = String(error?.message || "");

    if (code.includes("permission-denied")) {
        return "Firebase blocked write. In Console → Realtime Database → Rules, paste firebase-database.rules.json and Publish.";
    }
    if (code.includes("unavailable") || /404|not found|does not exist/i.test(message + code)) {
        return "Realtime Database is missing or the databaseURL is wrong.";
    }
    return message || "Chat failed. Check Firebase Realtime Database rules.";
}

function cssEscape(value) {
    if (window.CSS?.escape) {
        return window.CSS.escape(value);
    }
    return String(value).replace(/"/g, '\\"');
}
