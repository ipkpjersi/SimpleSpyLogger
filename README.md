# SimpleSpyLogger

A **multi-source** chat logger with a satirical project name. A small Laravel + MySQL app
stores every message; one or more standalone clients ("agents") feed it from whatever service
they can see. Five agents are implemented today: a BetterDiscord plugin (Discord) plus PHP
scrapers for Reddit, Lemmy, RSCPlus and Twitter/X.

```
SimpleSpyLogger/
  laravel/                  # Laravel app (based on laravel-breeze-starter): API + migrations + models
  sources/                  # One subfolder per agent. Folder name describes the implementation,
    betterdiscord/          #   not the `source` column value.
    reddit/                 # PHP scraper (writes to the DB directly via PDO)
    lemmy/                  # PHP scraper
    rscplus/                # PHP scraper
    twitter/                # PHP scraper (OAuth2 API top-up + archive import)
```

## Architecture

There are two ways data reaches the `messages` table:

```
  BD plugin       (source=discord)   -->  POST /api/messages/ingest  -->  MySQL
  reddit scraper  (source=reddit)    -->  PDO upsert                 -->  MySQL
  lemmy scraper   (source=lemmy)     -->  PDO upsert                 -->  MySQL
  rscplus scraper (source=rscplus)   -->  PDO upsert                 -->  MySQL
  twitter scraper (source=twitter)   -->  PDO upsert                 -->  MySQL
```

The BetterDiscord plugin runs inside Discord (no PHP available), so it POSTs the generic
event payload to the token-authenticated ingest API. The four PHP scrapers run on a box that
already has DB access, so they skip the HTTP hop and upsert straight into the `messages` table
via PDO. Either way the row shape is identical, distinguished only by a `source` string.
Each PHP scraper reads its own `sources/<name>/.env` (copy from the adjacent `.env.example`)
for DB credentials and source-specific settings.

The Laravel site doesn't know or care who wrote a row - it just displays whatever is in the table.
Keeping the agents standalone means they don't require a full Laravel install to run.

## Database schema

Two tables. Everything generic is a real column; everything source-specific
goes in the `payload` JSON.

### `messages`

One row per `(source, external_id)`. `deleted_at` is set when an agent sees a
delete.

| column                  | notes                                                            |
| ----------------------- | ---------------------------------------------------------------- |
| id                      | PK                                                               |
| **source**              | `discord` / `twitter` / `rscplus` / ...                          |
| **external_id**         | upstream-native ID; `(source, external_id)` is unique            |
| container_external_id   | guild / world / "space" - nullable                               |
| container_name          | nullable                                                         |
| channel_external_id     | channel / RSC chat-type / conversation - nullable                |
| channel_name            | nullable                                                         |
| visibility              | `public` / `private` / `group`                                   |
| author_external_id      | indexed                                                          |
| author_username         |                                                                  |
| author_display_name     | nullable                                                         |
| author_bot              | bool                                                             |
| content                 | longtext, nullable                                               |
| referenced_external_id  | replies / quote-tweets                                           |
| sent_at                 | upstream-reported send time                                      |
| source_edited_at        | upstream-reported last edit time                                 |
| deleted_at              | when an agent observed the delete (soft-delete)                  |
| captured_at             | when we first ingested it                                        |
| **payload**             | json: full source-specific blob (attachments, embeds, RSC packet, etc.) |
| created_at / updated_at |                                                                  |

Indexes: unique `(source, external_id)`; covering `(source, channel_external_id, sent_at)`, `(source, author_external_id, sent_at)`, `(source, container_external_id, sent_at)`.

Note: `deleted_at` here tracks an upstream/source-side delete, not a Laravel
soft delete. The admin "delete message" action is a real hard delete - it
removes the row. Because ingest dedups purely on `(source, external_id)`, a
hard-deleted row has no memory it ever existed, so re-ingesting that
`external_id` later (most realistically a JSON re-import or a source backfill;
forward-only live ingest won't normally resend old messages) will re-create it.
If we ever want hard deletes to stick, we could add a separate soft-delete flag
on the row (distinct from `deleted_at`, which is taken) or a small "deleted"
tombstone table keyed on `(source, external_id)`, and have the ingest handlers
skip anything flagged there. Fine to leave as-is for now.

### `message_revisions`

One row per edit. Snapshots the prior `content` + `payload` before overwrite.

| column            | notes                          |
| ----------------- | ------------------------------ |
| id                | PK                             |
| message_id        | FK -> messages, cascade delete |
| content           | previous content               |
| payload           | previous payload               |
| source_edited_at  | edit time on the prior version |
| captured_at       | when we observed the edit      |
| created_at        |                                |

## Ingest API

`POST /api/messages/ingest`, bearer-token auth (`Authorization: Bearer <INGEST_TOKEN>`).

```jsonc
{
  "source": "discord",
  "events": [
    {
      "type": "create" | "update" | "delete",
      "captured_at": "2026-05-13T15:42:00Z",
      "message": {
        "external_id": "1234567890",
        "container_external_id": "111",   // guild / world / null
        "container_name": "My Server",
        "channel_external_id": "222",     // channel / chat-type / null
        "channel_name": "general",
        "visibility": "public",           // public | private | group
        "author_external_id": "333",
        "author_username": "ken",
        "author_display_name": "Ken",
        "author_bot": false,
        "content": "hello world",
        "referenced_external_id": null,   // reply / quote target
        "sent_at": "2026-05-13T15:41:58Z",
        "source_edited_at": null,
        "payload": { /* anything source-specific */ }
      }
    }
  ]
}
```

For `delete` events the `message` object only needs `external_id` (plus
optionally `channel_external_id` / `container_external_id` if you want them
recorded on a stub row when the original was never seen).

Response: `{ "ok": true, "stats": { "created": N, "updated": N, "deleted": N, "skipped": N } }`.

## Agents

### Discord (BetterDiscord plugin) - included

`sources/betterdiscord/SimpleSpyLogger.plugin.js`. Subscribes to Flux dispatcher
`MESSAGE_CREATE`/`UPDATE`/`DELETE`, looks up channel/guild metadata, and emits
generic events with `source: "discord"`. Settings: API URL, token, batch knobs,
**excluded user IDs**, **excluded guild IDs**, enable toggle.

### Reddit (PHP scraper) - included

`sources/reddit/download.php`. Fetches the latest comments for the configured Reddit
user(s) via the `user/<name>.json` endpoint and upserts them with `source: "reddit"`.
Reddit returns 403 for anonymous requests to these endpoints as of 2026, so an
authenticated browser session cookie (`REDDIT_SESSION_COOKIE`) is required; the scraper
can auto-refresh it from the live Chrome profile (`refresh_cookie.php`) before each run,
which is why its cron line needs the desktop D-Bus session exported (see the header comment
in `download.php`). Mapping: subreddit -> container, post -> channel, commenter -> author.
Single request per user per run (most recent `REDDIT_PER_PAGE` comments, up to 100; no
historical backfill).

### Lemmy (PHP scraper) - included

`sources/lemmy/download.php`. Fetches every comment for the configured Lemmy user(s)
through the instance's `/api/v3` endpoint and upserts them with `source: "lemmy"`. The
Lemmy API is public, so unlike Reddit no cookie or keyring/D-Bus is involved - a plain cron
line is enough. Mapping: community -> container, post -> channel, commenter -> author.

### RSCPlus (PHP scraper) - included

`sources/rscplus/download.php`. Reads the most recently modified `*.log` file in the RSCPlus
logs folder (`RSCPLUS_LOGS_DIR`) and upserts public chat, global chat and private-message
lines with `source: "rscplus"`. Friend login/logout notices are skipped. RSCPlus chat logs
have no per-line timestamps, so `sent_at` is taken from the session-start time encoded in the
log filename; `external_id` is a stable `world:sha256(logfile#line#raw)` so re-running on a
growing log is idempotent. Mapping: world (`RSCPLUS_WORLD`, e.g. preservation/cabbage/etc) -> container, chat type (public/global/private) -> channel,
speaker -> author. Private/clan/dueling map to `visibility: private`, public/trade/global to
`public`.

As noted below, the client only ever sees your own PMs (in and out), never other players' PMs
to each other; those would require a server-side hook on a world you operate.

### Twitter/X (PHP scraper) - included

Two scripts under `sources/twitter/`:

- `import_archive.php` - one-time backfill of a downloaded X data archive (tweets + DMs).
- `download.php` - incremental "top-up" that pulls anything newer than what's already stored
  via the X API v2 and upserts it with `source: "twitter"`. Auth is OAuth 2.0 Authorization
  Code with PKCE using read-only scopes (`tweet.read`, `users.read`, `dm.read`,
  `offline.access`); each account is authorized once via `oauth2_authorize.php`, which stores
  a rotating refresh token in `.env`. Configure any number of accounts as `TWITTER_A1_*`,
  `TWITTER_A2_*`, ... Runs at most a couple of times a day since reads are billed per tweet.

**Known limitation:** X's XChat end-to-end encrypted DMs are not returned by `/2/dm_events`
(the endpoint 200s with older plaintext history but silently omits encrypted messages), so a
run can report "0 new DMs" while replies are visible in the browser. This is an X platform
limitation - capturing those would require a client-side userscript on a PIN-unlocked session.
See the note next to `TWITTER_SCRAPE_DMS` in `.env.example`.

Tweet mapping: tweet ID -> `external_id`, no container (Twitter has no "server"),
conversation/DM thread -> channel, author handle -> author; `payload` carries the tweet
`kind` (tweet/retweet/quote/reply/dm), media, entities and metrics.

## Setup

### MySQL

```sql
CREATE DATABASE simple_spy_logger CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Laravel

`composer install` and `php artisan key:generate` already done. `INGEST_TOKEN`
is already in `laravel/.env` - keep this value, you'll paste it into the agent.

```bash
cd laravel
php artisan migrate
php artisan app:seed-messages   # optional: load sample data for all 3 sources
npm install && npm run build    # build the Vite assets used by the /messages page
php artisan serve --port=7000   # http://127.0.0.1:7000
```

Register a user at `/register` (Breeze auth), then visit `/messages` for the
DataTables view of every ingested message across all sources. Server-side
pagination, sorting on every column, global search box.

### Messages CLI

All four are registered with the same `app:*` style as the rest of the project:

| Command                    | What it does                                                                                |
| -------------------------- | ------------------------------------------------------------------------------------------- |
| `app:seed-messages`        | Run sample seeders. `--source=discord\|twitter\|rscplus` to limit; default seeds all three. |
| `app:clear-messages`       | Delete messages (revisions cascade). `--source=...` to limit, `--force` to skip the prompt. |
| `app:export-messages`      | Dump to JSON. `{path}` is a file (with `--source=`) or a directory (without). `--since=YYYY-MM-DD` to slice. |
| `app:import-messages`      | Read a file produced by `app:export-messages` (or anything matching the ingest API shape) and replay it through the ingest pipeline. |

Export/import use the **same JSON shape as the ingest API**, so a file dumped
by one instance is directly replayable into another (or back into the same
instance after a clear). The full export -> clear -> import cycle is lossless.

The ingest pipeline itself lives in `app/Services/`:

- `MessageIngestService` - applies a batch of create/update/delete events. Used by the API controller, the import service, and (transitively) the import command.
- `MessageImportService` - reads a JSON file and hands it to the ingest service.
- `MessageExportService` - dumps rows back to the same JSON shape.

### BetterDiscord plugin

Copy `sources/betterdiscord/SimpleSpyLogger.plugin.js` to `~/.config/BetterDiscord/plugins/`,
enable it in Discord (Settings -> BetterDiscord -> Plugins), open its settings,
and paste:

- **API URL:** `http://127.0.0.1:7000/api/messages/ingest`
- **API Token:** the `INGEST_TOKEN` from `laravel/.env`
- *(optional)* **Included / Excluded user IDs** and **Included / Excluded guild IDs** -
  one ID per line. If an "Included" list has entries it acts as a whitelist for that
  dimension and the matching "Excluded" list is ignored.

The plugin sends batches every `batchIntervalMs` (default 25s) or whenever the
queue hits `maxBatchSize` (default 100).

### PHP scrapers (reddit / lemmy / rscplus / twitter)

Each lives in its own `sources/<name>/` folder and runs standalone under any PHP CLI
with PDO + curl - no Laravel needed. Copy `.env.example` to `.env` in that folder, fill in
the DB credentials and source-specific settings, then:

```bash
php sources/lemmy/download.php     # one manual run
```

Most take `no-delay` (skip the randomized start delay for manual/test runs) and are meant to
run from cron. See the header comment in each `download.php` for the exact cron line - Reddit
in particular needs the desktop D-Bus session exported so it can refresh its cookie from the
keyring; the others are plain cron lines. Twitter additionally needs a one-time
`oauth2_authorize.php` per account and, optionally, `import_archive.php` to backfill history
before the first `download.php` top-up.

## Heads-up

- Logging messages other users send you (or post in shared servers) without
  their knowledge has obvious privacy implications and is against Discord's ToS
  for self-bots / scrapers. Treat the data accordingly.
- Same applies to the Reddit, Lemmy, Twitter and RSCPlus agents. Note that the RSCPlus
  client can only capture private messages it actually receives or sends - i.e.
  PMs to/from you. Other players' PMs to each other are never delivered to your
  client and so cannot be logged from this side; capturing those would require
  a server-side hook on a server you operate, not a client agent. 
  That would be beyond the scope of this project, this project is about your 
  messages and any public messages.
  Public/clan/trade/global chat the client does see is fair game for logging.
- HTTP on localhost is fine; if you ever expose Laravel publicly, put it behind
  TLS and rotate the ingest token.

## `_unused_files/` (trash bin)

Anything we've removed but don't want to lose yet goes in `_unused_files/`.
Gitignored at the repo root, so it stays on disk for recovery but never ends up
in commits. To soft-delete something, `mv` it in there. To hard-delete, blow
away the whole folder.
