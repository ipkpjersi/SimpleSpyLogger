# Discord web collector plan

Replacement for the BetterDiscord plugin (`sources/betterdiscord/SimpleSpyLogger.plugin.js`)
before Discord's native desktop client ships.

## Why

On 2026-11-04 Discord's CTO confirmed a desktop rewrite on GPUI (Zed's native Rust UI
framework): "Electron goes away, and so does React Native." An early Windows 11 build
("Phoenix") exists; there is no release date, no formal announcement, and no word on Linux.
Without Electron there is no JS runtime, webpack or DOM, so BetterDiscord, Vencord and our
plugin stop working on that client. The web client (discord.com/app) will probably follow
via Rust compiled to WASM, which would also kill webpack/Flux hooking in the browser.

What survives either way: a WASM app still has to use the browser's own `WebSocket` for the
gateway. Chrome's DevTools protocol exposes every websocket frame
(`Network.webSocketFrameReceived`) without touching the page, so reading the gateway there
works the same for the React client today and a WASM client later.

## Approach

A long-running daemon drives a headless Chrome on a dedicated, logged-in profile, holds
discord.com/app open, reads gateway frames over CDP, and posts the same payload the plugin
sends to the ingest API. Nothing is injected into the page and nothing is ever sent on the
gateway; the real client does all the talking (same posture as the plugin, not a self-bot).

The ingest API and DB need no changes if the payload and `external_id` match the plugin.

## Phase 1: probe (written, waiting on a real run)

`gateway_probe.py` measures what an idle client receives, per server.

All testing of the web source uses a separate test account, not the main one, until the
ban risk of a 24/7 headless session is better understood. The BD plugin on the main account
is not used as a comparison baseline.

- [ ] Prepare the test account so the results mean something: let it age a little, add
      phone/email verification, and join a mix of servers (a small one, a few mid-size, and
      at least one large one, ideally ones the main account also cares about). Also create
      a small test server it is in, for known-traffic checks.
- [ ] `python3 sources/discord_web/gateway_probe.py --login` and log in as the test account.
      Do the login in the visible window, not by copying a token around.
- [ ] Run for a few hours: `python3 sources/discord_web/gateway_probe.py --duration 14400`.
- [ ] Known-traffic check: during the run, send a handful of messages, an edit and a delete
      in the test server and a DM to the test account (from another account or device), and
      confirm each shows up in the counts.
- [ ] Read the summary / `probe_report.json` (gitignored) and answer:
  - Which `compress` / `encoding` does the web gateway use? zstd-stream needs
    `pip install zstandard` (Python 3.12 has no stdlib zstd); ETF is already decoded.
  - Which servers deliver `MESSAGE_CREATE` while idle, and which only `PASSIVE_UPDATE_V2`?
    Note the `large` flag and member counts of the passive ones to find the cutoff.
  - Do DMs and group DMs all arrive? (Expected: yes.)
  - Did the client send any subscription ops (14 / 37) on its own while idle?
  - Did every known-traffic message, edit, delete and DM arrive?
  - Did the session survive the run (no forced logout, no captcha on the next start)?

### First results (2026-11-06, test account, 55 minutes in)

- Gateway: `compress=zlib-stream`, `encoding=json`, `v=9`. Decodes with the stdlib; no
  zstandard or ETF needed today. Zero decode errors, one connection, no reconnects.
- 99 servers. 546 `MESSAGE_CREATE`, 119 `MESSAGE_UPDATE`, 60 `MESSAGE_DELETE` while idle.
- One DM `MESSAGE_CREATE` (plus one update) arrived. The probe does not record authors, so
  the sender is confirmed by timing only.
- Passive-only servers get `PASSIVE_UPDATE_V2` (not V1). 12 of them, all 81k+ members
  (smallest 81,241). The largest server with full messages had 74,314 members, so the
  cutoff sits somewhere between those two counts.
- The `large` flag is useless as a signal (it is true for servers of a few hundred
  members); use `member_count`.
- The idle client sent no subscription ops (14 / 37), as expected.
- Many servers show zero events of any kind; they were just quiet during the window.

## Phase 2: decide large-server coverage (depends on probe)

If some servers are passive-only while idle:

- Preferred: have the daemon navigate the real client's tab to
  `https://discord.com/channels/<guild_id>/<channel_id>` for a configured list of servers,
  rotating on a timer, so the client subscribes itself exactly as when you click. Check in
  the probe report whether one visit keeps a server subscribed or it lapses, which sets the
  rotation interval.
- Avoid: sending op 14 / op 37 subscription frames ourselves. That is talking on the gateway
  as the user account (self-bot territory) and is detectable.
- Accept the gap: for servers that are not worth it, rely on `PASSIVE_UPDATE_V2` only to
  know something was missed (it carries `last_message_id` per channel, no content).

If everything arrives while idle, skip this phase.

## Phase 3: the daemon (`sources/discord_web/collector.py`)

Built on the probe's pieces (`GatewayStream`, `etf_decode`, `ProbeCdp`, launch helpers from
`sources/browser/browser_fetch.py`).

### Event handling

| Gateway dispatch      | Action                                                        |
|-----------------------|---------------------------------------------------------------|
| `READY`               | Seed caches: `guilds` (name may be under `properties`), their `channels` and `threads`, `private_channels` (recipients or `recipient_ids` + `users`), `users`. |
| `GUILD_CREATE/UPDATE/DELETE`, `CHANNEL_*`, `THREAD_*`, `THREAD_LIST_SYNC` | Keep caches current. |
| `MESSAGE_CREATE`      | type `create`                                                 |
| `MESSAGE_UPDATE`      | type `update` (may be partial, e.g. embed-only; same as the plugin today) |
| `MESSAGE_DELETE`      | type `delete` stub                                            |
| `MESSAGE_DELETE_BULK` | one `delete` stub per id (the plugin ignores this event; new coverage) |

### Mapping

Port `mapToGeneric()` 1:1 so rows merge with plugin-written ones:

- `external_id` = message id, `container_external_id` = guild id, `channel_external_id` = channel id.
- Gateway fields are snake_case (`channel_id`, `guild_id`, `edited_timestamp`,
  `global_name`, `sticker_items`); the plugin already handles both spellings.
- Channel, guild and user names come from the caches instead of `ChannelStore`,
  `GuildStore`, `UserStore`. DM channel name = recipients' `global_name || username || id`.
- Visibility rules unchanged (type 1 -> private, type 3 -> group, no guild -> private).
- ETF snowflakes arrive as ints: stringify every id.
- No optimistic local echoes on the gateway, so that check goes away.
- Keep the 10 s (type, id) dedup; a gateway RESUME can replay events.

### Config

`sources/discord_web/.env` (already covered by `sources/*/.env` in .gitignore) with a
committed `.env.example` using placeholders only. Same settings as the plugin:
`INGEST_URL`, `INGEST_TOKEN`, batch interval / size, max queue, and the include/exclude
lists (user ids, usernames, guild ids, channel names) with the plugin's semantics.

### Delivery

POST `{"source": "discord", "events": [...]}` with `Authorization: Bearer <token>`, re-queue
on failure, cap the queue, and spill the queue to a local file on shutdown so a restart does
not drop it.

### Supervision

- systemd user unit, `Restart=always`.
- Relaunch Chrome if it exits; reload the tab if no gateway frame (heartbeat ACKs come about
  every 40 s) arrives for ~3 minutes, or if the gateway socket closes and no new one opens.
- Logged-out detection (the `/login` pushState the probe catches) -> alert through
  `sources/notifier.php` and stop retrying until `--login` is run again.
- Optional periodic tab reload to cap memory growth; costs a short gap per reload, since a
  fresh IDENTIFY does not replay missed messages.
- Clean shutdown on SIGTERM (`Browser.close`) so rotated cookies are flushed to the profile.

### Headless details already handled in the probe

- Own profile (`sources/discord_web/profile/`) and port (9224), separate from the Reddit
  fetcher's, so the 24/7 tab never blocks its profile lock.
- `HeadlessChrome` removed from the User-Agent, also on worker sessions.
- `--password-store=basic` on both the login window and headless runs.
- Background throttling flags so heartbeats keep running with no visible window.
- Workers auto-attached in case the gateway socket moves off the main thread.

Not handled yet: the `Sec-CH-UA` client hints still list a HeadlessChrome brand. Fix with
`userAgentMetadata` on the UA override if Discord starts flagging the session.

## Phase 4: cutover

- [ ] Run the daemon on the test account for a few days against a dev SSL instance (or a
      DB backup), not the production DB. Check coverage with the known-traffic test server
      and, for passive-only servers, by comparing `PASSIVE_UPDATE_V2` `last_message_id`
      bumps against what was captured.
- [ ] Watch the test account for flags: forced logouts, captchas, verification prompts,
      "suspicious activity" emails. Note how long it has run without any.
- [ ] Only after that, decide whether to move the main account onto the web collector, keep
      the main on the BD plugin for as long as Electron lasts, or keep the test account as
      the permanent collector (it only sees servers it has joined, and no main-account DMs).
- [ ] When the main account switches (or Electron goes away), turn the plugin off; keep the
      file in the repo until the native client actually replaces Electron on Linux.
- [ ] Rows from the test account and the main account merge on `external_id` (message id),
      so a message seen by both is stored once; nothing extra is needed for that.
- [ ] Update `CLAUDE.md` / docs to point at the new collector.

## Risks

- Discord adds app-level encryption inside the WASM client, or moves the gateway protocol.
  Unlikely (bots and every client share the protocol) but it would end this approach.
- Headless detection (client hints, behaviour) leading to captchas or forced logouts.
- Account flags for a session that is online 24/7 from one profile. Same exposure as leaving
  the desktop client open, but worth watching; this is why testing uses a separate account.
- A brand-new test account is flagged more readily than an aged one (phone verification,
  captchas), so early flags on it may overstate the risk to the main account. Its
  large-server coverage results only carry over if it is in servers of comparable size.
- Large-server coverage may need ongoing tuning if Discord changes subscription rules.

## Fallbacks

- Pin the Electron client on Linux (`"SKIP_HOST_UPDATE": true` in
  `~/.config/discord/settings.json`) until Discord stops supporting old client versions.
- Bot account for servers you admin (no DMs).
- Discord data package for backfill of your own messages only.
