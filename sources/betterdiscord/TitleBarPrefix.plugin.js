/**
 * @name TitleBarPrefix
 * @description Injects a configurable prefix (default "BetterDiscord - ") into the Discord window titlebar.
 * @author ken
 * @version 4.2.1
 */
module.exports = class TitleBarPrefix {
    constructor(meta) {
        this.meta = meta;
        this.api = new BdApi(meta.name);
        this.nodeId = "titlebar-prefix-label";
        this.defaults = { prefix: "BetterDiscord - " };
        this.settings = Object.assign({}, this.defaults, this.api.Data.load("settings"));
        // Tracks which warnings have already been shown so the MutationObserver
        // (which fires constantly) does not spam toasts for the same failure.
        this.warned = new Set();
    }

    // The prefix currently in effect.
    get prefix() {
        return this.settings.prefix;
    }

    // Show a warning toast at most once per key until clearWarn() resets it.
    warnOnce(key, message) {
        if (this.warned.has(key)) return;
        this.warned.add(key);
        this.api.UI.showToast(`${this.meta.name}: ${message}`, { type: "warning", timeout: 8000 });
    }

    // Re-arm a warning so it can fire again if the failure recurs later.
    clearWarn(key) {
        this.warned.delete(key);
    }

    // Find the titlebar's title-text element. Webpack filters on the titlebar's
    // class names stopped matching, and the hash suffix changes per build (e.g.
    // "title_3zSH5d"), so locate it from the DOM instead: the window titlebar is
    // the "bar_<hash>" element containing both "title_<hash>" and
    // "trailing_<hash>" with the same hash. Other "bar_" elements, such as the
    // system bar or the unread mentions bar, do not have both.
    findTitleElement() {
        for (const bar of document.querySelectorAll('[class^="bar_"], [class*=" bar_"]')) {
            const barClass = Array.from(bar.classList).find((c) => c.startsWith("bar_"));
            const hash = barClass.slice("bar_".length);
            const title = bar.querySelector("." + CSS.escape("title_" + hash));
            if (title && bar.querySelector("." + CSS.escape("trailing_" + hash))) return title;
        }
        return null;
    }

    // Insert (or update) our label as the first child of the title element.
    injectLabel() {
        const target = this.findTitleElement();
        if (!target) return;

        let label = target.querySelector("#" + this.nodeId);
        if (!label) {
            label = document.createElement("span");
            label.id = this.nodeId;
            // Force an explicit colour/size; the title element's own text colour
            // does not reliably inherit to an injected span. Stay out of the
            // window drag region so dragging still works.
            label.style.cssText = "display:inline-flex;align-items:center;margin-right:6px;font-size:13px;font-weight:600;color:var(--header-primary,var(--text-default,#dbdee1)) !important;-webkit-text-fill-color:var(--header-primary,var(--text-default,#dbdee1));-webkit-app-region:no-drag;white-space:nowrap;";
            target.prepend(label);
        }
        label.textContent = this.prefix;

        // Verify the text actually renders. If our prefix has content and the
        // titlebar is on-screen but the label measures ~0 wide, Discord's title
        // styling changed and the colour/visibility hack no longer applies.
        if (this.prefix) {
            requestAnimationFrame(() => {
                if (!label.isConnected) return;
                const targetVisible = target.getBoundingClientRect().width > 0;
                const labelWidth = label.getBoundingClientRect().width;
                if (targetVisible && labelWidth < 2) {
                    this.warnOnce(
                        "color-hack",
                        "prefix is injected but not visible - the titlebar's text styling changed. The colour workaround needs an update."
                    );
                } else if (labelWidth >= 2) {
                    this.clearWarn("color-hack");
                }
            });
        }
    }

    removeLabel() {
        const label = document.getElementById(this.nodeId);
        if (label) label.remove();
    }

    start() {
        // Re-inject whenever Discord re-renders the titlebar (window state
        // changes, channel switches, settings open/close, etc.).
        this.observer = new MutationObserver(() => {
            if (!document.getElementById(this.nodeId)) this.injectLabel();
        });
        this.observer.observe(document.body, { childList: true, subtree: true });

        this.injectLabel();

        // Discord may not have rendered the titlebar yet when the plugin starts,
        // so only warn if it still has not appeared after a grace period.
        this.missingCheck = setTimeout(() => {
            if (!document.getElementById(this.nodeId)) {
                this.warnOnce("titlebar-missing", "could not find the titlebar - Discord's markup may have changed. The plugin needs an update.");
            }
        }, 15000);
    }

    stop() {
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
        clearTimeout(this.missingCheck);
        this.removeLabel();
    }

    getSettingsPanel() {
        return this.api.UI.buildSettingsPanel({
            settings: [
                {
                    type: "text",
                    id: "prefix",
                    name: "Title prefix",
                    note: "Text shown at the start of the Discord titlebar. Include any trailing space or separator you want.",
                    value: this.settings.prefix,
                },
            ],
            onChange: (_category, id, value) => {
                if (id !== "prefix") return;
                this.settings.prefix = value;
                this.api.Data.save("settings", this.settings);
                this.injectLabel();
            },
        });
    }
};
