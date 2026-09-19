import { initializeApp } from "https://www.gstatic.com/firebasejs/12.19.0/firebase-app.js";
import {
    getDatabase,
    ref,
    onValue,
    remove,
} from "https://www.gstatic.com/firebasejs/12.19.0/firebase-database.js";
import { firebaseConfig } from "./firebase-config.js";

const els = {
    list: document.getElementById("dm-list"),
    status: document.getElementById("dm-status"),
};

const deleting = new Set();

boot();

function boot() {
    if (!els.list) {
        return;
    }

    try {
        if (!firebaseConfig.databaseURL) {
            throw new Error("databaseURL is missing in firebase-config.js");
        }

        const app = initializeApp(firebaseConfig);
        const db = getDatabase(app);
        const messagesRef = ref(db, "chat/messages");

        onValue(messagesRef, (snapshot) => {
            const rows = [];
            snapshot.forEach((child) => {
                const value = child.val() || {};
                rows.push({
                    id: child.key,
                    nick: String(value.nick || "Anon"),
                    text: String(value.text || ""),
                    imageUrl: String(value.imageUrl || value.image || ""),
                    createdAt: Number(value.createdAt) || 0,
                });
            });

            rows.sort((a, b) => b.createdAt - a.createdAt);
            renderList(rows, db);
            setStatus(`${rows.length} message${rows.length === 1 ? "" : "s"}`);
        }, (error) => {
            console.error(error);
            setStatus(error?.message || "Firebase error.", true);
            els.list.innerHTML = `<p class="chat-empty">Could not load messages.</p>`;
        });
    } catch (error) {
        console.error(error);
        setStatus(error?.message || "Could not connect.", true);
        els.list.innerHTML = `<p class="chat-empty">Could not connect to Firebase.</p>`;
    }
}

function renderList(rows, db) {
    if (!rows.length) {
        els.list.innerHTML = `<p class="chat-empty">No messages.</p>`;
        return;
    }

    const frag = document.createDocumentFragment();
    for (const row of rows) {
        frag.append(buildRow(row, db));
    }
    els.list.replaceChildren(frag);
}

function buildRow(row, db) {
    const article = document.createElement("article");
    article.className = "dm-row";
    article.dataset.id = row.id;

    const main = document.createElement("div");
    main.className = "dm-row-main";

    const meta = document.createElement("div");
    meta.className = "dm-row-meta";

    const nick = document.createElement("strong");
    nick.textContent = row.nick;

    const time = document.createElement("time");
    time.textContent = formatTime(row.createdAt);

    meta.append(nick, time);

    const body = document.createElement("div");
    body.className = "dm-row-body";

    if (row.imageUrl) {
        const img = document.createElement("img");
        img.className = "dm-row-image";
        img.src = row.imageUrl;
        img.alt = "Chat image";
        img.loading = "lazy";
        body.append(img);
    }

    if (row.text) {
        const text = document.createElement("p");
        text.textContent = row.text;
        body.append(text);
    }

    if (!row.text && !row.imageUrl) {
        const empty = document.createElement("p");
        empty.className = "dm-row-empty";
        empty.textContent = "(empty message)";
        body.append(empty);
    }

    main.append(meta, body);

    const del = document.createElement("button");
    del.type = "button";
    del.className = "dm-delete-btn";
    del.textContent = "Delete";
    del.addEventListener("click", async () => {
        if (deleting.has(row.id)) {
            return;
        }
        deleting.add(row.id);
        del.disabled = true;
        del.textContent = "…";
        article.classList.add("is-deleting");
        try {
            await remove(ref(db, `chat/messages/${row.id}`));
        } catch (error) {
            console.error(error);
            setStatus(error?.message || "Delete failed.", true);
            deleting.delete(row.id);
            del.disabled = false;
            del.textContent = "Delete";
            article.classList.remove("is-deleting");
        }
    });

    article.append(main, del);
    return article;
}

function formatTime(createdAt) {
    if (!createdAt) {
        return "";
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
    els.status.textContent = message;
    els.status.classList.toggle("is-error", Boolean(isError));
}
