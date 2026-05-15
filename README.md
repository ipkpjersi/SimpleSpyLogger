# SimpleSpyLogger

A **multi-source** chat logger with a satirical project name. A small Laravel + MySQL app exposes a single
ingest API; one or more clients ("agents") POST messages to it from whatever
service they can see. The first agent is a BetterDiscord plugin; 
RSCPlus and other agents are sketched below.

```
SimpleSpyLogger/
  laravel/                  # Laravel app (based on laravel-breeze-starter): API + migrations + models
  sources/                  # One subfolder per agent. Folder name describes the implementation,
    betterdiscord/          # not the `source` column value. Add more as needed:
                            # e.g. sources/twitter/, sources/rscplus/.
```

## Architecture

```
  BD plugin      (source=discord)   -->  POST /api/messages/ingest  -->  MySQL
  Twitter script (source=twitter)   -->  POST /api/messages/ingest  -->  MySQL
  RSCPlus client (source=rscplus)   -->  POST /api/messages/ingest  -->  MySQL
```

Every agent sends the **same payload shape**, distinguished only by a `source` string identifying the app. 
The Laravel site doesn't know or care who's sending data - it just upserts the data to our db.
We could technically make most agents run within the Laravel site itself, but having them be
standalone makes them not require having Laravel installed to run which is helpful.

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

### Twitter - not implemented, contract below

Likely shapes for a future Twitter agent (browser extension or Twitter API client):

```jsonc
{
  "source": "twitter",
  "events": [
    {
      "type": "create",
      "captured_at": "...",
      "message": {
        "external_id": "1825xxxxxxxxxx",     // tweet ID
        "container_external_id": null,       // Twitter has no "server"
        "channel_external_id": null,         // or conversation_id for threads / DM
        "visibility": "public",              // "private" for DMs
        "author_external_id": "44196397",
        "author_username": "elonmusk",
        "author_display_name": "Elon Musk",
        "author_bot": false,
        "content": "tweet text",
        "referenced_external_id": "1824xxxxxxxxxx",  // reply/quote target
        "sent_at": "...",
        "payload": {
          "kind": "tweet" | "retweet" | "quote" | "reply" | "dm",
          "media": [...],
          "entities": { ... },
          "metrics": { "likes": N, "retweets": N, ... }
        }
      }
    }
  ]
}
```

### RSCPlus - not implemented, contract below

OpenRSC chat is split into channel "types" (public, private, clan, trade,
dueling) per world. A future agent would hook the chat dispatch path in the
RSCPlus client (or read server-side log tables) and emit:

```jsonc
{
  "source": "rscplus",
  "events": [
    {
      "type": "create",
      "captured_at": "...",
      "message": {
        "external_id": "preservation:8429371",  // world + monotonic seq, or hash
        "container_external_id": "preservation", // world: preservation/cabbage/openpk/2001scape/uranium/coleslaw
        "container_name": "Preservation",
        "channel_external_id": "private",        // public | private | clan | trade | dueling | global
        "channel_name": "Private message",
        "visibility": "private",                 // private/clan/dueling -> private, public/trade/global -> public
        "author_external_id": "Zezima",          // RSC has no numeric user ID, use username
        "author_username": "Zezima",
        "author_display_name": null,
        "author_bot": false,
        "content": "hi",
        "referenced_external_id": null,          // RSC has no replies
        "sent_at": "...",
        "payload": {
          "recipient": "Ken",                    // for PMs
          "chat_color": 0,
          "world_revision": 38,
          "x": 220, "y": 451                     // for in-world public chat
        }
      }
    }
  ]
}
```

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

## Heads-up

- Logging messages other users send you (or post in shared servers) without
  their knowledge has obvious privacy implications and is against Discord's ToS
  for self-bots / scrapers. Treat the data accordingly.
- Same applies to any future Twitter / RSCPlus agents. Note that the RSCPlus
  client can only capture private messages it actually receives or sends - i.e.
  PMs to/from you. Other players' PMs to each other are never delivered to your
  client and so cannot be logged from this side; capturing those would require
  a server-side hook on an OpenRSC world you operate, not a client agent. 
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
