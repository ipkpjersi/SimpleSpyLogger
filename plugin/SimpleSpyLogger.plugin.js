/**
 * @name SimpleSpyLogger
 * @description Logs every Discord message you can see to the SimpleSpyLogger Laravel ingest API.
 * @author ken
 * @version 0.2.0
 */
module.exports = class SimpleSpyLogger {
    constructor(meta) {
        this.meta = meta;
        this.api = new BdApi(meta.name);

        this.defaultSettings = {
            apiUrl: "http://127.0.0.1:8000/api/messages/ingest",
            apiToken: "",
            batchIntervalMs: 5000,
            maxBatchSize: 100,
            maxQueueSize: 5000,
            enabled: true,
            excludedUserIds: "",   // newline-separated Discord user IDs
            excludedGuildIds: "",  // newline-separated Discord guild IDs
        };

        this.queue = [];
        this.flushTimer = null;
        this.dispatcher = null;
        this.channelStore = null;
        this.guildStore = null;
        this.subscriptions = [];
        this.flushing = false;
    }

    getSettings() {
        return Object.assign({}, this.defaultSettings, this.api.Data.load("settings") || {});
    }

    saveSettings(s) {
        this.api.Data.save("settings", s);
    }

    parseIdList(text) {
        return new Set(
            (text || "")
                .split(/\r?\n|,/)
                .map(s => s.trim())
                .filter(Boolean)
        );
    }

    start() {
        this.dispatcher = this.findDispatcher();
        if (!this.dispatcher) {
            this.api.UI.showToast("SimpleSpyLogger: Flux dispatcher not found", { type: "error" });
            return;
        }
        this.channelStore = this.findStore("ChannelStore", ["getChannel"]);
        this.guildStore = this.findStore("GuildStore", ["getGuild"]);

        const subs = {
            MESSAGE_CREATE: (e) => this.handleEvent("create", e.message),
            MESSAGE_UPDATE: (e) => this.handleEvent("update", e.message),
            MESSAGE_DELETE: (e) => this.handleEvent("delete", {
                id: String(e.id),
                channel_id: String(e.channelId || ""),
                guild_id: e.guildId ? String(e.guildId) : null,
            }),
        };
        for (const [event, fn] of Object.entries(subs)) {
            this.dispatcher.subscribe(event, fn);
            this.subscriptions.push([event, fn]);
        }

        const interval = Math.max(500, this.getSettings().batchIntervalMs);
        this.flushTimer = setInterval(() => this.flush(), interval);
        this.api.UI.showToast("SimpleSpyLogger started", { type: "success" });
    }

    stop() {
        if (this.flushTimer) {
            clearInterval(this.flushTimer);
            this.flushTimer = null;
        }
        if (this.dispatcher) {
            for (const [event, fn] of this.subscriptions) {
                try { this.dispatcher.unsubscribe(event, fn); } catch (_) {}
            }
        }
        this.subscriptions = [];
        this.flush();
    }

    findDispatcher() {
        const { Webpack } = this.api;
        try {
            const mod = Webpack.getModule(
                (m) => m && typeof m.dispatch === "function" && typeof m.subscribe === "function" && typeof m.register === "function",
                { searchExports: true }
            );
            if (mod) return mod;
        } catch (_) {}
        try { return Webpack.getByKeys("dispatch", "subscribe", "register"); } catch (_) {}
        return null;
    }

    findStore(name, requiredKeys) {
        const { Webpack } = this.api;
        try {
            if (typeof Webpack.getStore === "function") {
                const s = Webpack.getStore(name);
                if (s) return s;
            }
        } catch (_) {}
        try { return Webpack.getByKeys(...requiredKeys); } catch (_) {}
        return null;
    }

    handleEvent(type, message) {
        const s = this.getSettings();
        if (!s.enabled || !message || !message.id) return;

        const userId = String(message.author?.id || "");
        const guildId = message.guild_id ? String(message.guild_id) : "";

        const excludedUsers = this.parseIdList(s.excludedUserIds);
        const excludedGuilds = this.parseIdList(s.excludedGuildIds);

        if (userId && excludedUsers.has(userId)) return;
        if (guildId && excludedGuilds.has(guildId)) return;

        const generic = this.mapToGeneric(message, type === "delete");

        if (this.queue.length >= s.maxQueueSize) {
            this.queue.shift();
        }
        this.queue.push({
            type,
            captured_at: new Date().toISOString(),
            message: generic,
        });

        if (this.queue.length >= s.maxBatchSize) this.flush();
    }

    mapToGeneric(m, isDeleteStub) {
        const channelId = m.channel_id ? String(m.channel_id) : null;
        const channel = (channelId && this.channelStore && this.channelStore.getChannel)
            ? this.channelStore.getChannel(channelId)
            : null;
        const channelType = channel ? channel.type : null;
        const channelName = channel ? (channel.name || null) : null;

        const guildId = m.guild_id ? String(m.guild_id) : null;
        const guild = (guildId && this.guildStore && this.guildStore.getGuild)
            ? this.guildStore.getGuild(guildId)
            : null;
        const guildName = guild ? (guild.name || null) : null;

        let visibility = "public";
        if (channelType === 1) visibility = "private";
        else if (channelType === 3) visibility = "group";
        else if (!guildId) visibility = "private";

        const author = m.author || {};
        const base = {
            external_id: String(m.id),
            container_external_id: guildId,
            container_name: guildName,
            channel_external_id: channelId,
            channel_name: channelName,
            visibility,
            author_external_id: String(author.id || "0"),
            author_username: author.username || "[unknown]",
            author_display_name: author.global_name || null,
            author_bot: !!author.bot,
            content: m.content ?? null,
            referenced_external_id: m.referenced_message?.id
                ? String(m.referenced_message.id)
                : (m.message_reference?.message_id ? String(m.message_reference.message_id) : null),
            sent_at: m.timestamp || null,
            source_edited_at: m.edited_timestamp || null,
        };

        if (isDeleteStub) return base;

        base.payload = {
            type: m.type ?? null,
            channel_type: channelType,
            flags: m.flags ?? null,
            attachments: m.attachments ?? null,
            embeds: m.embeds ?? null,
            sticker_items: m.sticker_items ?? null,
            mentions: m.mentions ?? null,
        };
        return base;
    }

    async flush() {
        if (this.flushing || !this.queue.length) return;
        const s = this.getSettings();
        if (!s.apiUrl || !s.apiToken) return;

        this.flushing = true;
        const batch = this.queue.splice(0, Math.max(1, s.maxBatchSize));
        try {
            const body = JSON.stringify({ source: "discord", events: batch });
            const useBdNet = this.api.Net && typeof this.api.Net.fetch === "function";
            const fetchFn = useBdNet ? this.api.Net.fetch.bind(this.api.Net) : fetch;

            const res = await fetchFn(s.apiUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Authorization": "Bearer " + s.apiToken,
                },
                body,
            });

            const ok = res && (res.ok === true || (res.status >= 200 && res.status < 300));
            if (!ok) {
                this.queue.unshift(...batch);
                console.error("[SimpleSpyLogger] flush HTTP", res && res.status);
            }
        } catch (err) {
            this.queue.unshift(...batch);
            console.error("[SimpleSpyLogger] flush error", err);
        } finally {
            this.flushing = false;
        }
    }

    getSettingsPanel() {
        const s = this.getSettings();
        const root = document.createElement("div");
        root.style.padding = "12px";
        root.style.color = "var(--text-normal)";

        const mkInput = (label, key, type) => {
            const wrap = document.createElement("div");
            wrap.style.marginBottom = "12px";
            const lbl = document.createElement("label");
            lbl.textContent = label;
            lbl.style.display = "block";
            lbl.style.marginBottom = "4px";
            lbl.style.fontWeight = "600";
            const input = document.createElement("input");
            input.type = type || "text";
            input.value = s[key];
            input.style.width = "100%";
            input.style.padding = "6px 8px";
            input.style.background = "var(--background-secondary)";
            input.style.color = "var(--text-normal)";
            input.style.border = "1px solid var(--background-tertiary)";
            input.style.borderRadius = "4px";
            input.addEventListener("change", () => {
                const cur = this.getSettings();
                cur[key] = (type === "number") ? Number(input.value) : input.value;
                this.saveSettings(cur);
            });
            wrap.appendChild(lbl);
            wrap.appendChild(input);
            return wrap;
        };

        const mkTextarea = (label, key, hint) => {
            const wrap = document.createElement("div");
            wrap.style.marginBottom = "12px";
            const lbl = document.createElement("label");
            lbl.textContent = label;
            lbl.style.display = "block";
            lbl.style.marginBottom = "4px";
            lbl.style.fontWeight = "600";
            const sub = document.createElement("div");
            sub.textContent = hint;
            sub.style.fontSize = "12px";
            sub.style.color = "var(--text-muted)";
            sub.style.marginBottom = "4px";
            const ta = document.createElement("textarea");
            ta.value = s[key];
            ta.rows = 4;
            ta.style.width = "100%";
            ta.style.padding = "6px 8px";
            ta.style.background = "var(--background-secondary)";
            ta.style.color = "var(--text-normal)";
            ta.style.border = "1px solid var(--background-tertiary)";
            ta.style.borderRadius = "4px";
            ta.style.fontFamily = "monospace";
            ta.addEventListener("change", () => {
                const cur = this.getSettings();
                cur[key] = ta.value;
                this.saveSettings(cur);
            });
            wrap.appendChild(lbl);
            wrap.appendChild(sub);
            wrap.appendChild(ta);
            return wrap;
        };

        root.appendChild(mkInput("API URL", "apiUrl"));
        root.appendChild(mkInput("API Token (Bearer)", "apiToken", "password"));
        root.appendChild(mkInput("Batch interval (ms)", "batchIntervalMs", "number"));
        root.appendChild(mkInput("Max batch size", "maxBatchSize", "number"));
        root.appendChild(mkInput("Max queue size", "maxQueueSize", "number"));
        root.appendChild(mkTextarea("Excluded user IDs", "excludedUserIds", "One Discord user ID per line. Messages from these users will not be logged."));
        root.appendChild(mkTextarea("Excluded guild IDs", "excludedGuildIds", "One Discord guild (server) ID per line. Messages in these servers will not be logged."));

        const toggleWrap = document.createElement("div");
        toggleWrap.style.marginTop = "6px";
        const cb = document.createElement("input");
        cb.type = "checkbox";
        cb.id = "ssl_enabled";
        cb.checked = !!s.enabled;
        cb.addEventListener("change", () => {
            const cur = this.getSettings();
            cur.enabled = cb.checked;
            this.saveSettings(cur);
        });
        const cbl = document.createElement("label");
        cbl.htmlFor = "ssl_enabled";
        cbl.textContent = " Logging enabled";
        cbl.style.marginLeft = "6px";
        toggleWrap.appendChild(cb);
        toggleWrap.appendChild(cbl);
        root.appendChild(toggleWrap);

        const status = document.createElement("div");
        status.style.marginTop = "12px";
        status.style.fontSize = "12px";
        status.style.color = "var(--text-muted)";
        status.textContent = "Queue: " + this.queue.length;
        const tick = setInterval(() => {
            if (!document.body.contains(root)) { clearInterval(tick); return; }
            status.textContent = "Queue: " + this.queue.length;
        }, 1000);
        root.appendChild(status);

        return root;
    }
};
