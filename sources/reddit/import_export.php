<?php
/**
 * SimpleSpyLogger - Reddit account data-export importer (history backfill).
 *
 * Reads one or more official Reddit data exports (reddit.com/settings/data-request,
 * "All time") and upserts the account's comments, posts, private messages and
 * chat messages into the messages table. download.php only ever sees the newest
 * comments, so the export is the only way to get the older history.
 *
 * What it reads out of each ZIP. Every other file (votes, saved items, IP logs,
 * ...) is ignored, and the `ip` column of the files below is never stored:
 *   statistics.csv        owner account name and export time
 *   comments.csv          the account's comments
 *   posts.csv             the account's posts (submissions)
 *   messages_archive.csv  old-style inbox private messages  (if REDDIT_IMPORT_PMS)
 *   chat_history.csv      Reddit chat messages             (if REDDIT_IMPORT_CHAT)
 *
 * Message-table mapping. Comments match download.php exactly, so rows it has
 * already scraped merge instead of duplicating:
 *   comment  external_id reddit.com:t1_<id>, public; subreddit -> container,
 *            post -> channel, parent comment -> referenced_external_id
 *   post     external_id reddit.com:t3_<id>, public; subreddit -> container,
 *            the post itself -> channel (so it groups with its comments);
 *            content is the title, then the body or the link url
 *   pm       external_id reddit.com:t4_<id>, private; owner account ->
 *            container, PM thread -> channel named after the other party
 *   chat     external_id reddit.com:chat:<event id>, private; owner account ->
 *            container, chat room -> channel, thread parent -> referenced
 * payload.kind says which of the four a row is (scraped rows have no kind and
 * are comments).
 *
 * The export has no post titles or subreddit ids for comments. Ones already
 * known from scraped rows or from the exports' own posts are filled in; the rest
 * are left null for backfill_titles.php to fetch afterwards. Other users appear
 * by name only, so their author_external_id is '0', the placeholder
 * MessageIngestService also uses for an unknown author.
 *
 * Timestamps in the export are UTC and are stored in APP_TIMEZONE like the
 * scraper's. captured_at and, for [deleted]/[removed] bodies, deleted_at are the
 * export time, since that is when the export observed them.
 *
 * Re-running is idempotent. An existing row is only changed when its text
 * differs and it was last written before the export was generated, i.e. the
 * export is the newer copy; the old text is kept in message_revisions, like the
 * scrapers do. Rows scraped after the export was generated are left alone.
 *
 * Usage:
 *   php import_export.php                  import every *.zip in this folder
 *   php import_export.php a.zip b.zip      import just the given exports
 *   php import_export.php dry-run [zips]   parse and compare, write nothing
 *
 * Config is the scraper's .env next to this script (DB_*, APP_TIMEZONE, alerts,
 * REDDIT_BROWSER_*), plus REDDIT_IMPORT_PMS and REDDIT_IMPORT_CHAT.
 */

declare(strict_types=1);

$root = __DIR__;

require_once __DIR__.'/../notifier.php';
require_once __DIR__.'/../browser/browser_fetch.php';

/** Bodies Reddit leaves behind when a comment, post or message is deleted. */
const DELETED_BODIES = ['[deleted]', '[removed]'];

/** Rows per transaction while writing. */
const COMMIT_EVERY = 1000;

function load_env(string $path): array
{
    if (! is_file($path)) {
        fwrite(STDERR, "Missing env file: $path (copy .env.example to .env)\n");
        exit(1);
    }
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[strlen($val) - 1] === $val[0]) {
            $val = substr($val, 1, -1);
        }
        $env[$key] = $val;
    }

    return $env;
}

function env_bool(array $env, string $key, bool $default): bool
{
    if (! array_key_exists($key, $env)) {
        return $default;
    }

    return in_array(strtolower(trim($env[$key])), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Read one CSV member of an export zip into header-keyed rows, or null when the
 * zip has no such member. The export is written Python-style: quotes doubled,
 * no backslash escaping, so the escape character is disabled.
 *
 * @return array<int, array<string, string>>|null
 */
function read_export_csv(ZipArchive $zip, string $name): ?array
{
    if ($zip->locateName($name) === false) {
        return null;
    }
    $stream = $zip->getStream($name);
    if ($stream === false) {
        return null;
    }
    $header = fgetcsv($stream, null, ',', '"', '');
    if (! is_array($header)) {
        fclose($stream);

        return [];
    }
    $rows = [];
    while (($fields = fgetcsv($stream, null, ',', '"', '')) !== false) {
        if ($fields === [null]) {
            continue;
        }
        $row = [];
        foreach ($header as $i => $key) {
            $row[(string) $key] = (string) ($fields[$i] ?? '');
        }
        $rows[] = $row;
    }
    fclose($stream);

    return $rows;
}

/** Export timestamp ("2026-11-03 02:07:15 UTC") -> local 'Y-m-d H:i:s', or null. */
function export_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);

    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

/** Post id out of a comment's link or a post permalink (".../comments/<id>/..."). */
function post_id_from_url(string $url): ?string
{
    return preg_match('#/comments/([A-Za-z0-9]+)#', $url, $m) ? $m[1] : null;
}

/** Full reddit.com URL -> the path, which is how download.php stores permalinks. */
function reddit_path(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);

    return is_string($path) ? $path : '';
}

/**
 * Export user reference -> author_username: "/u/name" -> "name" (bare, like the
 * scraper stores), "/r/name" -> "r/name" (subreddit modmail), "" -> "".
 */
function export_author(string $value): string
{
    $value = trim($value);
    if (strncmp($value, '/u/', 3) === 0) {
        return substr($value, 3);
    }

    return ltrim($value, '/');
}

/** Export user reference -> display label for a channel: "u/name" or "r/name". */
function export_label(string $value): string
{
    return ltrim(trim($value), '/');
}

/** @param array<string, string> $row */
function gildings_payload(array $row): ?array
{
    $counts = [];
    foreach (['gildings' => 'total', 'gildings_silver' => 'silver', 'gildings_supergold' => 'supergold'] as $col => $key) {
        $n = (int) ($row[$col] ?? 0);
        if ($n > 0) {
            $counts[$key] = $n;
        }
    }

    return $counts ?: null;
}

function clip(?string $value, int $max): ?string
{
    return $value === null ? null : mb_substr($value, 0, $max);
}

/**
 * Owner's t2_ account id: from a row already in the DB, else Reddit's about.json
 * through the dedicated browser profile.
 */
function resolve_owner_id(PDO $pdo, string $username, array $browserOpts): ?string
{
    $q = $pdo->prepare(
        "SELECT author_external_id FROM messages
          WHERE source = 'reddit' AND author_username = :u AND author_external_id LIKE 't2\\_%'
          LIMIT 1"
    );
    $q->execute([':u' => $username]);
    $id = $q->fetchColumn();
    if (is_string($id) && $id !== '') {
        return $id;
    }

    $resp = browser_fetch('https://www.reddit.com/user/'.rawurlencode($username).'/about.json?raw_json=1', $browserOpts);
    if (! $resp['ok']) {
        fwrite(STDERR, "Could not look up u/$username: ".($resp['error'] ?? 'HTTP '.$resp['code'])."\n");

        return null;
    }
    $data = json_decode((string) $resp['body'], true);
    $id = is_array($data) ? ($data['data']['id'] ?? null) : null;

    return is_string($id) && $id !== '' ? 't2_'.$id : null;
}

$env = load_env($root.'/.env');
date_default_timezone_set($env['APP_TIMEZONE'] ?? 'UTC');

if (! class_exists('ZipArchive')) {
    fwrite(STDERR, "The PHP zip extension (ZipArchive) is required but not loaded.\n");
    exit(1);
}

$cliArgs = array_slice($argv, 1);
$dryRunFlags = ['dry-run', '--dry-run'];
$dryRun = (bool) array_intersect($cliArgs, $dryRunFlags);
$exports = array_values(array_filter(
    $cliArgs,
    static fn (string $a): bool => $a !== '' && ! in_array($a, $dryRunFlags, true)
));
if (! $exports) {
    $exports = glob($root.'/*.zip') ?: [];
}
if (! $exports) {
    fwrite(STDERR, "No exports to import. Pass zip paths as arguments or drop the exports in $root.\n");
    exit(1);
}

$importPms = env_bool($env, 'REDDIT_IMPORT_PMS', true);
$importChat = env_bool($env, 'REDDIT_IMPORT_CHAT', true);
$browserOpts = [
    'profile' => $env['REDDIT_BROWSER_PROFILE'] ?? '',
    'port' => $env['REDDIT_BROWSER_PORT'] ?? '',
    'binary' => $env['REDDIT_BROWSER_BINARY'] ?? '',
];

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $env['DB_HOST'] ?? '127.0.0.1',
    $env['DB_PORT'] ?? '3306',
    $env['DB_DATABASE'] ?? ''
);
try {
    $pdo = new PDO($dsn, $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'DB connection failed: '.$e->getMessage()."\n");
    exit(1);
}

$lookup = $pdo->prepare(
    'SELECT id, content, payload, source_edited_at, updated_at
       FROM messages
       WHERE source = :source AND external_id = :external_id'
);

$insert = $pdo->prepare(
    'INSERT IGNORE INTO messages
        (source, external_id, container_external_id, container_name,
         channel_external_id, channel_name, visibility,
         author_external_id, author_username, author_display_name, author_bot,
         content, referenced_external_id, sent_at, source_edited_at,
         deleted_at, captured_at, payload, created_at, updated_at)
     VALUES
        (:source, :external_id, :container_external_id, :container_name,
         :channel_external_id, :channel_name, :visibility,
         :author_external_id, :author_username, :author_display_name, :author_bot,
         :content, :referenced_external_id, :sent_at, :source_edited_at,
         :deleted_at, :captured_at, :payload, :created_at, :updated_at)'
);

$revision = $pdo->prepare(
    'INSERT INTO message_revisions
        (message_id, content, payload, source_edited_at, captured_at, created_at)
     VALUES
        (:message_id, :content, :payload, :source_edited_at, :captured_at, :created_at)'
);

$update = $pdo->prepare(
    'UPDATE messages
        SET content = :content,
            payload = :payload,
            deleted_at = COALESCE(deleted_at, :deleted_at),
            updated_at = :updated_at
        WHERE id = :id'
);

// Metadata the export lacks, learned from rows already in the DB: subreddit
// name (lowercased) -> t5_ id, and post t3_ id -> title.
/** @var array<string, string> $subredditIds */
$subredditIds = [];
foreach ($pdo->query(
    "SELECT container_name, container_external_id FROM messages
      WHERE source = 'reddit' AND container_external_id LIKE 't5\\_%' AND container_name IS NOT NULL
      GROUP BY container_name, container_external_id"
) as $r) {
    $subredditIds[strtolower((string) $r['container_name'])] = (string) $r['container_external_id'];
}
/** @var array<string, string> $postTitles */
$postTitles = [];
foreach ($pdo->query(
    "SELECT channel_external_id, MAX(channel_name) AS title FROM messages
      WHERE source = 'reddit' AND channel_external_id LIKE 't3\\_%' AND channel_name IS NOT NULL
      GROUP BY channel_external_id"
) as $r) {
    $postTitles[(string) $r['channel_external_id']] = (string) $r['title'];
}

// Open every export up front, read its owner and export time, and add its own
// posts' titles to the map so comments on them (by either account) get a title.
/** @var array<int, array{path: string, zip: ZipArchive, owner: string, exported_at: string}> $opened */
$opened = [];
$failures = [];
foreach ($exports as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "Export not found: $path\n");
        $failures[] = ['export' => $path, 'error' => 'file not found'];
        continue;
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        fwrite(STDERR, "Could not open zip: $path\n");
        $failures[] = ['export' => $path, 'error' => 'could not open zip'];
        continue;
    }
    $stats = [];
    foreach (read_export_csv($zip, 'statistics.csv') ?? [] as $r) {
        $stats[$r['statistic'] ?? ''] = $r['value'] ?? '';
    }
    $owner = trim($stats['account name'] ?? '');
    $exportedAt = export_date($stats['export time'] ?? '');
    if ($owner === '' || $exportedAt === null) {
        fwrite(STDERR, "No usable statistics.csv (account name, export time) in $path; skipping.\n");
        $failures[] = ['export' => $path, 'error' => 'missing/invalid statistics.csv'];
        $zip->close();
        continue;
    }
    foreach (read_export_csv($zip, 'posts.csv') ?? [] as $p) {
        if (($p['id'] ?? '') !== '' && ($p['title'] ?? '') !== '') {
            $postTitles['t3_'.$p['id']] = $p['title'];
        }
    }
    $opened[] = ['path' => $path, 'zip' => $zip, 'owner' => $owner, 'exported_at' => $exportedAt];
}

$now = date('Y-m-d H:i:s');
$pending = 0;
/** @var array<string, true> $seenNew dry run only: ids already counted as new */
$seenNew = [];

/**
 * Write one mapped row. Returns 'inserted', 'updated' (export is newer, old text
 * kept as a revision), 'kept' (DB copy differs but is newer than the export),
 * or 'unchanged'. Commits every COMMIT_EVERY writes.
 */
$upsert = static function (array $row, string $exportedAt) use (
    $pdo, $lookup, $insert, $revision, $update, $dryRun, $now, &$pending, &$seenNew
): string {
    $payloadJson = json_encode($row['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $lookup->execute([':source' => 'reddit', ':external_id' => $row['external_id']]);
    $existing = $lookup->fetch(PDO::FETCH_ASSOC);

    if ($existing === false) {
        if ($dryRun) {
            if (isset($seenNew[$row['external_id']])) {
                return 'unchanged';
            }
            $seenNew[$row['external_id']] = true;

            return 'inserted';
        }
        if (! $pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        $insert->execute([
            ':source' => 'reddit',
            ':external_id' => $row['external_id'],
            ':container_external_id' => $row['container_external_id'],
            ':container_name' => clip($row['container_name'], 255),
            ':channel_external_id' => $row['channel_external_id'],
            ':channel_name' => clip($row['channel_name'], 255),
            ':visibility' => $row['visibility'],
            ':author_external_id' => $row['author_external_id'],
            ':author_username' => clip($row['author_username'], 255),
            ':author_display_name' => null,
            ':author_bot' => 0,
            ':content' => $row['content'],
            ':referenced_external_id' => $row['referenced_external_id'],
            ':sent_at' => $row['sent_at'],
            ':source_edited_at' => $row['source_edited_at'] ?? null,
            ':deleted_at' => $row['deleted_at'],
            ':captured_at' => $exportedAt,
            ':payload' => $payloadJson,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $result = $insert->rowCount() > 0 ? 'inserted' : 'unchanged';
    } else {
        if ($row['content'] === $existing['content']) {
            return 'unchanged';
        }
        // Rows download.php stored from the html format never match the raw
        // markdown byte for byte, so a text difference there is not an edit.
        // Only a deletion is.
        $stored = json_decode((string) ($existing['payload'] ?? ''), true);
        $storedSource = is_array($stored) && isset($stored['content_source']) ? (string) $stored['content_source'] : 'json';
        if ($storedSource === 'html' && $row['deleted_at'] === null) {
            return 'unchanged';
        }
        // The newer copy wins: a row last written after the export was
        // generated (the scraper saw it later) is kept as is.
        if ($existing['updated_at'] !== null && (string) $existing['updated_at'] >= $exportedAt) {
            return 'kept';
        }
        if ($dryRun) {
            return 'updated';
        }
        if (! $pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        $revision->execute([
            ':message_id' => $existing['id'],
            ':content' => $existing['content'],
            ':payload' => $existing['payload'],
            ':source_edited_at' => $existing['source_edited_at'],
            ':captured_at' => $exportedAt,
            ':created_at' => $now,
        ]);
        $update->execute([
            ':id' => $existing['id'],
            ':content' => $row['content'],
            ':payload' => $payloadJson,
            ':deleted_at' => $row['deleted_at'],
            ':updated_at' => $now,
        ]);
        $result = 'updated';
    }

    // A failed statement throws before this point but leaves the transaction
    // open (MySQL does not abort it), so the next write simply joins it.
    if (++$pending >= COMMIT_EVERY) {
        $pdo->commit();
        $pending = 0;
    }

    return $result;
};

$flush = static function () use ($pdo, &$pending): void {
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    $pending = 0;
};

$totals = ['inserted' => 0, 'updated' => 0, 'kept' => 0, 'unchanged' => 0, 'errors' => 0];
$prefix = $dryRun ? 'Reddit export (dry run)' : 'Reddit export';

/**
 * Run $rows through $map (row -> message row, or null to skip) and $upsert, and
 * print one summary line for this kind.
 *
 * @param array<int, array<string, string>> $rows
 */
$importKind = static function (string $label, string $kind, array $rows, callable $map, string $exportedAt) use (
    $upsert, $flush, &$totals, $prefix
): void {
    $counts = ['inserted' => 0, 'updated' => 0, 'kept' => 0, 'unchanged' => 0, 'errors' => 0];
    foreach ($rows as $r) {
        $row = $map($r);
        if ($row === null) {
            $counts['errors']++;
            continue;
        }
        try {
            $counts[$upsert($row, $exportedAt)]++;
        } catch (PDOException $e) {
            fwrite(STDERR, "Skipped $kind {$row['external_id']}: ".$e->getMessage()."\n");
            $counts['errors']++;
        }
    }
    $flush();
    foreach ($counts as $k => $n) {
        $totals[$k] += $n;
    }
    echo "$prefix: $label - ".count($rows)." $kind read, {$counts['inserted']} new, {$counts['updated']} updated, "
        ."{$counts['kept']} kept (DB newer), {$counts['unchanged']} unchanged, {$counts['errors']} skipped.\n";
};

foreach ($opened as $export) {
    $zip = $export['zip'];
    $owner = $export['owner'];
    $exportedAt = $export['exported_at'];
    $label = 'u/'.$owner;

    $ownerId = resolve_owner_id($pdo, $owner, $browserOpts);
    if ($ownerId === null) {
        fwrite(STDERR, "Could not resolve the account id of $label; skipping {$export['path']}.\n");
        $failures[] = ['export' => $export['path'], 'error' => "could not resolve the account id of $label"];
        $zip->close();
        continue;
    }
    echo "$prefix: $label ($ownerId), exported $exportedAt, from ".basename($export['path']).".\n";

    // Posts first, so a post row exists before the comments under it.
    $posts = read_export_csv($zip, 'posts.csv') ?? [];
    $importKind($label, 'posts', $posts, static function (array $p) use ($owner, $ownerId, $exportedAt, $subredditIds): ?array {
        if ($p['id'] === '') {
            return null;
        }
        $url = $p['url'];
        // Self posts link to their own permalink (relative or absolute);
        // link posts and crossposts point somewhere else.
        $isSelf = $url === '' || $url[0] === '/' || post_id_from_url($url) === $p['id'];
        $content = $p['title'];
        if ($p['body'] !== '') {
            $content .= "\n\n".$p['body'];
        } elseif (! $isSelf) {
            $content .= "\n\n".$url;
        }
        $subId = $subredditIds[strtolower($p['subreddit'])] ?? null;
        $payload = [
            'kind' => 'post',
            'permalink' => reddit_path($p['permalink']),
            'title' => $p['title'],
            'url' => $url !== '' ? $url : null,
            'is_self' => $isSelf,
            'subreddit_id' => $subId,
            'imported_from' => 'export',
        ];
        if (($g = gildings_payload($p)) !== null) {
            $payload['gildings'] = $g;
        }

        return [
            'external_id' => 'reddit.com:t3_'.$p['id'],
            'container_external_id' => $subId,
            'container_name' => $p['subreddit'] !== '' ? $p['subreddit'] : null,
            'channel_external_id' => 't3_'.$p['id'],
            'channel_name' => $p['title'] !== '' ? $p['title'] : null,
            'visibility' => 'public',
            'author_external_id' => $ownerId,
            'author_username' => $owner,
            'content' => $content,
            'referenced_external_id' => null,
            'sent_at' => export_date($p['date']),
            'deleted_at' => in_array($p['body'], DELETED_BODIES, true) ? $exportedAt : null,
            'payload' => $payload,
        ];
    }, $exportedAt);

    $comments = read_export_csv($zip, 'comments.csv') ?? [];
    $importKind($label, 'comments', $comments, static function (array $c) use ($owner, $ownerId, $exportedAt, $subredditIds, $postTitles): ?array {
        if ($c['id'] === '') {
            return null;
        }
        $postId = post_id_from_url($c['link']) ?? post_id_from_url($c['permalink']);
        $channelId = $postId !== null ? 't3_'.$postId : null;
        $subId = $subredditIds[strtolower($c['subreddit'])] ?? null;
        $payload = [
            'kind' => 'comment',
            'permalink' => reddit_path($c['permalink']),
            'subreddit_id' => $subId,
            'link_id' => $channelId,
            'link_permalink' => $c['link'] !== '' ? $c['link'] : null,
            // The export body is the same raw markdown comments.json returns
            // (checked against scraped rows), so it is marked json: if
            // download.php fetches this comment again it compares the text,
            // see reddit_content_changed().
            'content_source' => 'json',
            'imported_from' => 'export',
        ];
        if (($g = gildings_payload($c)) !== null) {
            $payload['gildings'] = $g;
        }
        if ($c['media'] !== '') {
            $payload['media'] = $c['media'];
        }

        return [
            'external_id' => 'reddit.com:t1_'.$c['id'],
            'container_external_id' => $subId,
            'container_name' => $c['subreddit'] !== '' ? $c['subreddit'] : null,
            'channel_external_id' => $channelId,
            'channel_name' => $channelId !== null ? ($postTitles[$channelId] ?? null) : null,
            'visibility' => 'public',
            'author_external_id' => $ownerId,
            'author_username' => $owner,
            'content' => $c['body'],
            // The export's parent is a bare id that is set only for replies to
            // another comment (empty for top-level comments), matching
            // download.php, which references t1_ parents only.
            'referenced_external_id' => $c['parent'] !== '' ? 'reddit.com:t1_'.$c['parent'] : null,
            'sent_at' => export_date($c['date']),
            'deleted_at' => in_array($c['body'], DELETED_BODIES, true) ? $exportedAt : null,
            'payload' => $payload,
        ];
    }, $exportedAt);

    if ($importPms) {
        $pms = read_export_csv($zip, 'messages_archive.csv') ?? [];
        $importKind($label, 'private messages', $pms, static function (array $m) use ($owner, $ownerId, $exportedAt): ?array {
            if ($m['id'] === '') {
                return null;
            }
            $sender = export_author($m['from']);
            $fromOwner = strcasecmp($sender, $owner) === 0;
            // The other party names the thread; a note to self is with the owner.
            $partner = export_label($fromOwner ? $m['to'] : $m['from']);
            // A thread's first message has no thread_id; it is the thread.
            $threadId = $m['thread_id'] !== '' ? $m['thread_id'] : 't4_'.$m['id'];

            return [
                'external_id' => 'reddit.com:t4_'.$m['id'],
                'container_external_id' => $ownerId,
                'container_name' => 'u/'.$owner,
                'channel_external_id' => $threadId,
                'channel_name' => $partner !== '' ? $partner : 'PM',
                'visibility' => 'private',
                'author_external_id' => $fromOwner ? $ownerId : '0',
                'author_username' => $sender !== '' ? $sender : '[unknown]',
                'content' => $m['body'],
                'referenced_external_id' => null,
                'sent_at' => export_date($m['date']),
                'deleted_at' => in_array($m['body'], DELETED_BODIES, true) ? $exportedAt : null,
                'payload' => [
                    'kind' => 'pm',
                    // The export's own permalink is malformed ("https:/www...").
                    'permalink' => '/message/messages/'.$m['id'],
                    'subject' => $m['subject'],
                    'from' => export_label($m['from']),
                    'to' => export_label($m['to']),
                    'thread_id' => $threadId,
                    'imported_from' => 'export',
                ],
            ];
        }, $exportedAt);
    }

    if ($importChat) {
        $chats = read_export_csv($zip, 'chat_history.csv') ?? [];
        // Direct chats have no name, so name the room after everyone in it
        // other than the owner.
        /** @var array<string, array<string, true>> $roomPeople */
        $roomPeople = [];
        foreach ($chats as $ch) {
            $who = export_label($ch['username']);
            if ($who !== '' && strcasecmp(export_author($ch['username']), $owner) !== 0) {
                $roomPeople[$ch['channel_url']][$who] = true;
            }
        }
        $importKind($label, 'chat messages', $chats, static function (array $ch) use ($owner, $ownerId, $exportedAt, $roomPeople): ?array {
            if ($ch['message_id'] === '') {
                return null;
            }
            $roomUrl = $ch['channel_url'];
            $pos = strpos($roomUrl, '/room/');
            $roomId = $pos !== false ? substr($roomUrl, $pos + 6) : $roomUrl;
            if ($roomId === '' || strlen($roomId) > 64) {
                fwrite(STDERR, "Chat message {$ch['message_id']} has an unusable room url: $roomUrl\n");

                return null;
            }
            $sender = export_author($ch['username']);
            $fromOwner = strcasecmp($sender, $owner) === 0;
            $name = $ch['channel_name'];
            if ($name === '') {
                $people = array_keys($roomPeople[$roomUrl] ?? []);
                sort($people);
                $name = $people ? 'Chat: '.implode(', ', $people) : 'Chat';
            }
            $payload = [
                'kind' => 'chat',
                'conversation_type' => $ch['conversation_type'] !== '' ? $ch['conversation_type'] : null,
                'room_url' => $roomUrl,
                'imported_from' => 'export',
            ];
            if ($ch['subreddit'] !== '') {
                $payload['subreddit'] = $ch['subreddit'];
            }

            return [
                'external_id' => 'reddit.com:chat:'.$ch['message_id'],
                'container_external_id' => $ownerId,
                'container_name' => 'u/'.$owner,
                'channel_external_id' => $roomId,
                'channel_name' => $name,
                'visibility' => 'private',
                'author_external_id' => $fromOwner ? $ownerId : '0',
                'author_username' => $sender !== '' ? $sender : '[unknown]',
                'content' => $ch['message'],
                'referenced_external_id' => $ch['thread_parent_message_id'] !== ''
                    ? 'reddit.com:chat:'.$ch['thread_parent_message_id']
                    : null,
                'sent_at' => export_date($ch['created_at']),
                'source_edited_at' => $ch['updated_at'] !== $ch['created_at'] ? export_date($ch['updated_at']) : null,
                'deleted_at' => null,
                'payload' => $payload,
            ];
        }, $exportedAt);
    }

    $zip->close();
}

$summary = "$prefix: {$totals['inserted']} new, {$totals['updated']} updated, {$totals['kept']} kept (DB newer), "
    ."{$totals['unchanged']} unchanged, {$totals['errors']} skipped across ".count($opened).' export(s).';
echo $summary."\n";

if (! $dryRun) {
    log_import_summary($summary);
    $missing = (int) $pdo->query(
        "SELECT COUNT(DISTINCT channel_external_id) FROM messages
          WHERE source = 'reddit' AND channel_external_id LIKE 't3\\_%' AND channel_name IS NULL"
    )->fetchColumn();
    if ($missing > 0) {
        echo "$prefix: $missing posts have no title yet; run php backfill_titles.php to fetch them.\n";
    }
}

if ($failures) {
    $lines = [count($failures).' export(s) failed to import:', ''];
    foreach ($failures as $f) {
        $lines[] = $f['export'].': '.$f['error'];
    }
    fwrite(STDERR, implode("\n", $lines)."\n");
    if (! $dryRun) {
        send_alert($env, 'SimpleSpyLogger reddit importer: '.count($failures).' export(s) failed', implode("\n", $lines));
    }
    exit(1);
}
