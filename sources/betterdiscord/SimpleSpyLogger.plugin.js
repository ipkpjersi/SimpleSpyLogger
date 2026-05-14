/**
 * @name SimpleSpyLogger
 * @description Logs every Discord message you can see to the SimpleSpyLogger Laravel ingest API.
 * @author ken
 * @version 0.4.4
 */
module.exports = class SimpleSpyLogger {
    constructor(meta) {
        this.meta = meta;
        this.api = new BdApi(meta.name);

        this.defaultSettings = {
            apiUrl: "http://127.0.0.1:7000/api/messages/ingest",
            apiToken: "",
            batchIntervalMs: 25000,
            maxBatchSize: 100,
            maxQueueSize: 5000,
            enabled: true,
            debugLogEvents: false, // console.log the raw Flux event/message shape for each captured event
            includedUserIds: "",   // newline-separated Discord user IDs; if set, ONLY these are logged
            excludedUserIds: "",   // newline-separated Discord user IDs
            includedGuildIds: "",  // newline-separated Discord guild IDs; if set, ONLY these are logged
            excludedGuildIds: "",  // newline-separated Discord guild IDs
        };

        this.queue = [];
        this.flushTimer = null;
        this.dispatcher = null;
        this.channelStore = null;
        this.guildStore = null;
        this.userStore = null;
        this.subscriptions = [];
        this.flushing = false;
        this.recentKeys = new Map(); // "${type}:${external_id}" -> last-seen ms, for dedup
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

    // Discord message records use Moment objects for timestamps; raw gateway
    // payloads use ISO strings. Normalize both (and Date) to an ISO string.
    toIso(v) {
        if (!v) return null;
        if (typeof v === "string") return v;
        if (typeof v.toISOString === "function") {
            try { return v.toISOString(); } catch (_) { return null; }
        }
        return null;
    }

    refId(m) {
        if (!m) return null;
        if (m.referenced_message && m.referenced_message.id) return String(m.referenced_message.id);
        const ref = m.messageReference || m.message_reference;
        const id = ref && (ref.message_id || ref.messageId);
        return id ? String(id) : null;
    }

    intersects(a, b) {
        for (const x of a) {
            if (b.has(x)) return true;
        }
        return false;
    }

    start() {
        const version = (this.meta && this.meta.version) || "?";
        console.log("[SimpleSpyLogger] start() called, version " + version);

        this.dispatcher = this.findDispatcher();
        if (!this.dispatcher) {
            console.error("[SimpleSpyLogger] start(): Flux dispatcher NOT found - no events will be captured");
            this.api.UI.showToast("SimpleSpyLogger: Flux dispatcher not found", { type: "error" });
            return;
        }
        this.channelStore = this.findStore("ChannelStore", ["getChannel"]);
        this.guildStore = this.findStore("GuildStore", ["getGuild"]);
        this.userStore = this.findStore("UserStore", ["getUser"]);

        const subs = {
            MESSAGE_CREATE: (e) => this.handleEvent("create", e),
            MESSAGE_UPDATE: (e) => this.handleEvent("update", e),
            MESSAGE_DELETE: (e) => this.handleEvent("delete", e),
        };
        for (const [event, fn] of Object.entries(subs)) {
            this.dispatcher.subscribe(event, fn);
            this.subscriptions.push([event, fn]);
        }

        const interval = Math.max(500, this.getSettings().batchIntervalMs);
        this.flushTimer = setInterval(() => this.flush(), interval);
        console.log(
            "[SimpleSpyLogger] start() done - " + this.subscriptions.length +
            " subscription(s) attached, channelStore=" + !!this.channelStore +
            " guildStore=" + !!this.guildStore
        );
        this.api.UI.showToast("SimpleSpyLogger v" + version + " started", { type: "success" });
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

    handleEvent(type, event) {
        // Unconditional, count-limited trace so we can confirm the dispatcher
        // is actually delivering events without depending on any setting.
        if (this._traceCount === undefined) this._traceCount = 0;
        if (this._traceCount < 20) {
            this._traceCount++;
            console.log("[SimpleSpyLogger] handleEvent #" + this._traceCount + " type=" + type);
        }

        const s = this.getSettings();
        if (!s.enabled || !event) return;

        // When debugLogEvents is on, account for EVERY event: it is either
        // "queueing ..." or "SKIPPED (reason)". Lets us see drops, not guess.
        const dbg = (reason, extra) => {
            if (s.debugLogEvents) {
                console.log("[SimpleSpyLogger] " + type + " SKIPPED (" + reason + ")" + (extra ? " " + extra : ""));
            }
        };

        // Discord fires an optimistic MESSAGE_CREATE/UPDATE for your own
        // messages - a local echo with a temporary client-generated id -
        // before the confirmed gateway event. Skip it; the real one follows.
        if (event.optimistic) {
            dbg("optimistic echo", "id=" + (event.message && event.message.id));
            return;
        }

        const isDelete = type === "delete";
        const message = isDelete ? null : event.message;
        const messageId = isDelete ? event.id : (message && message.id);
        if (!messageId) { dbg("no messageId"); return; }

        // Flux MESSAGE_* events carry channelId/guildId at the event level
        // (camelCase). The message body may be a Discord record that omits
        // guild_id, so fall back to the resolved channel for the guild id.
        const channelId = String(event.channelId || (message && message.channel_id) || "");
        const channel = (channelId && this.channelStore && this.channelStore.getChannel)
            ? this.channelStore.getChannel(channelId)
            : null;
        const guildId = String(
            event.guildId || (message && message.guild_id) || (channel && channel.guild_id) || ""
        );
        const userId = isDelete ? "" : String((message && message.author && message.author.id) || "");

        // For DMs / group DMs the users involved in a conversation include the
        // channel recipients, not just the author - so an included/excluded
        // user id matches a message sent to OR from that user.
        const userIds = new Set();
        if (userId) userIds.add(userId);
        if (channel && Array.isArray(channel.recipients)) {
            for (const r of channel.recipients) {
                const rid = String((r && r.id) || r || "");
                if (rid) userIds.add(rid);
            }
        }

        const includedUsers = this.parseIdList(s.includedUserIds);
        const includedGuilds = this.parseIdList(s.includedGuildIds);
        const excludedUsers = this.parseIdList(s.excludedUserIds);
        const excludedGuilds = this.parseIdList(s.excludedGuildIds);

        // Each dimension is filtered only when the event actually has that id.
        // A missing id (a DM has no guild, a delete stub has no author) means
        // that filter does not apply - it never drops the event on its own.
        // When the id is present: a non-empty "included" list acts as a
        // whitelist (and the matching "excluded" list is ignored); otherwise
        // the "excluded" list applies. The user lists match a message sent to
        // OR from any listed user (author or DM recipient).
        if (userIds.size > 0) {
            if (includedUsers.size > 0) {
                if (!this.intersects(userIds, includedUsers)) { dbg("user not in include list", "id=" + messageId); return; }
            } else if (this.intersects(userIds, excludedUsers)) {
                dbg("user in exclude list", "id=" + messageId);
                return;
            }
        }
        if (guildId) {
            if (includedGuilds.size > 0) {
                if (!includedGuilds.has(guildId)) { dbg("guild not in include list", "id=" + messageId + " guild=" + guildId); return; }
            } else if (excludedGuilds.has(guildId)) {
                dbg("guild in exclude list", "id=" + messageId);
                return;
            }
        }

        // Discord can dispatch the same confirmed event more than once within
        // a few hundred ms; skip a (type, id) we have already queued recently.
        const dedupKey = type + ":" + messageId;
        const nowMs = Date.now();
        const lastSeen = this.recentKeys.get(dedupKey);
        if (lastSeen !== undefined && nowMs - lastSeen < 10000) {
            dbg("dedup", "key=" + dedupKey + " seen " + (nowMs - lastSeen) + "ms ago");
            return;
        }
        this.recentKeys.set(dedupKey, nowMs);
        if (this.recentKeys.size > 1000) {
            for (const [k, t] of this.recentKeys) {
                if (nowMs - t > 10000) this.recentKeys.delete(k);
            }
        }

        const generic = this.mapToGeneric({ type, message, messageId, channelId, channel, guildId });

        // Only log events that actually pass the filters and get queued - this
        // is the exact mapped object that will be sent to the ingest API.
        if (s.debugLogEvents) {
            console.log("[SimpleSpyLogger] queueing " + type + ":", generic);
        }

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

    mapToGeneric(ctx) {
        const { type, message: m, messageId, channelId, channel, guildId } = ctx;
        const isDeleteStub = type === "delete";

        const channelType = channel ? channel.type : null;
        let channelName = channel ? (channel.name || null) : null;
        // DMs and group DMs have no channel.name - use the recipient name(s)
        // so the channel column is meaningful for private conversations.
        if (!channelName && channel && Array.isArray(channel.recipients)) {
            const names = [];
            for (const r of channel.recipients) {
                const rid = String((r && r.id) || r || "");
                if (!rid) continue;
                const u = (this.userStore && this.userStore.getUser) ? this.userStore.getUser(rid) : null;
                names.push(u ? (u.globalName || u.username || rid) : rid);
            }
            if (names.length) channelName = names.join(", ");
        }

        const guild = (guildId && this.guildStore && this.guildStore.getGuild)
            ? this.guildStore.getGuild(guildId)
            : null;
        const guildName = guild ? (guild.name || null) : null;

        let visibility = "public";
        if (channelType === 1) visibility = "private";
        else if (channelType === 3) visibility = "group";
        else if (!guildId) visibility = "private";

        const author = (m && m.author) || {};
        const base = {
            external_id: String(messageId),
            container_external_id: guildId || null,
            container_name: guildName,
            channel_external_id: channelId || null,
            channel_name: channelName,
            visibility,
            author_external_id: String(author.id || "0"),
            author_username: author.username || "[unknown]",
            author_display_name: author.globalName || author.global_name || null,
            author_bot: !!author.bot,
            content: m ? (m.content ?? null) : null,
            referenced_external_id: this.refId(m),
            sent_at: m ? this.toIso(m.timestamp) : null,
            source_edited_at: m ? this.toIso(m.editedTimestamp || m.edited_timestamp) : null,
        };

        if (isDeleteStub) return base;

        base.payload = {
            type: m.type ?? null,
            channel_type: channelType,
            flags: m.flags ?? null,
            attachments: m.attachments ?? null,
            embeds: m.embeds ?? null,
            sticker_items: m.stickerItems ?? m.sticker_items ?? null,
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

            // Token preview only - never log the full token.
            const tokenPreview = s.apiToken
                ? (s.apiToken.length + " chars, starts \"" + s.apiToken.slice(0, 6) + "...\"")
                : "(EMPTY - set the API Token in plugin settings)";
            console.log(
                "[SimpleSpyLogger] flushing " + batch.length + " event(s) to " + s.apiUrl +
                " | transport: " + (useBdNet ? "BdApi.Net.fetch" : "window.fetch") +
                " | token: " + tokenPreview
            );

            const res = await fetchFn(s.apiUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Authorization": "Bearer " + s.apiToken,
                },
                body,
            });

            const status = res ? res.status : "(no response object)";
            let responseText = "";
            try {
                if (res && typeof res.text === "function") responseText = await res.text();
            } catch (e) {
                responseText = "(could not read response body: " + e + ")";
            }

            const ok = res && (res.ok === true || (res.status >= 200 && res.status < 300));
            if (!ok) {
                this.queue.unshift(...batch);
                console.error(
                    "[SimpleSpyLogger] flush HTTP " + status +
                    " | response body: " + (responseText || "(empty)") +
                    " | re-queued " + batch.length + " event(s)"
                );
                if (status === 401) {
                    console.error(
                        "[SimpleSpyLogger] 401 means the Bearer token did not match the server's " +
                        "INGEST_TOKEN. Check for an empty token, copy/paste whitespace, or a stale " +
                        "value, and confirm it equals INGEST_TOKEN in laravel/.env."
                    );
                }
            } else {
                console.log("[SimpleSpyLogger] flush OK " + status + " - " + batch.length + " event(s) accepted");
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
        root.appendChild(mkTextarea("Included user IDs", "includedUserIds", "One Discord user ID per line. If set, ONLY these users are logged and the Excluded user IDs list is ignored."));
        root.appendChild(mkTextarea("Excluded user IDs", "excludedUserIds", "One Discord user ID per line. Messages from these users will not be logged. Ignored when Included user IDs is set."));
        root.appendChild(mkTextarea("Included guild IDs", "includedGuildIds", "One Discord guild (server) ID per line. If set, ONLY these servers are logged and the Excluded guild IDs list is ignored."));
        root.appendChild(mkTextarea("Excluded guild IDs", "excludedGuildIds", "One Discord guild (server) ID per line. Messages in these servers will not be logged. Ignored when Included guild IDs is set."));

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

        const debugWrap = document.createElement("div");
        debugWrap.style.marginTop = "6px";
        const dcb = document.createElement("input");
        dcb.type = "checkbox";
        dcb.id = "ssl_debug";
        dcb.checked = !!s.debugLogEvents;
        dcb.addEventListener("change", () => {
            const cur = this.getSettings();
            cur.debugLogEvents = dcb.checked;
            this.saveSettings(cur);
        });
        const dcbl = document.createElement("label");
        dcbl.htmlFor = "ssl_debug";
        dcbl.textContent = " Debug: log raw Flux events to console";
        dcbl.style.marginLeft = "6px";
        debugWrap.appendChild(dcb);
        debugWrap.appendChild(dcbl);
        root.appendChild(debugWrap);

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
