<?php
/**
 * SimpleSpyLogger - Twitter/X account-archive importer (initial backfill).
 *
 * Reads one or more official "Your X data" ZIP exports and upserts their
 * tweets and direct messages into the messages table. This is the seeder that
 * fills in the full history; sources/twitter/download.php then tops it up with
 * new tweets/DMs via the API afterwards.
 *
 * The API can only reach back so far (and costs per tweet read), so the archive
 * is the authoritative source for everything up to the day it was generated.
 * Re-running this importer is idempotent: unchanged rows are skipped, and a
 * changed tweet body records a message_revisions entry just like the scrapers.
 *
 * What it reads out of each ZIP (media files are ignored entirely, only the
 * small data/*.js manifests are decompressed into memory):
 *   data/account.js               owner identity (account id, username, display)
 *   data/tweets.js                the account's own tweets and replies
 *   data/note-tweet.js            full body of long "note" tweets (>280 chars),
 *                                 which tweets.js stores truncated
 *   data/direct-messages.js       1:1 DM conversations       (if TWITTER_IMPORT_DMS)
 *   data/direct-messages-group.js group DM conversations     (if TWITTER_IMPORT_DMS)
 *
 * Message-table mapping:
 *   source                 'twitter'
 *   external_id            tweet/DM id_str (globally unique X snowflake)
 *   container_*            the owning account (id + username), so the UI can
 *                          group/filter by which account a row came from
 *   channel_*              null for tweets; the DM conversationId for DMs
 *   visibility             'public' for tweets, 'private' for DMs
 *   author_*               tweet owner for tweets; DM sender for DMs (username is
 *                          only known when the sender is the archive owner)
 *   referenced_external_id in_reply_to_status_id_str for replies
 *   payload                kind + metrics + entities + client + note/quote info
 *
 * Usage:
 *   php import_archive.php                       import every *.zip in this folder
 *   php import_archive.php a.zip b.zip           import just the given archives
 *   php import_archive.php test-alert            fire a test alert and exit
 *
 * Config lives in .env next to this script (copy .env.example). DMs are imported
 * only when TWITTER_IMPORT_DMS is true.
 */

$root = __DIR__;

require_once __DIR__.'/../notifier.php';
require_once __DIR__.'/twitter_util.php';

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
 * Twitter's archive files are JavaScript assignments of the form
 * `window.YTD.<name>.part0 = [ ... ]`. Strip everything up to the first `[` and
 * decode the JSON array that follows. Returns [] for missing/empty manifests.
 */
function decode_ytd(?string $raw): array
{
    if ($raw === null) {
        return [];
    }
    $start = strpos($raw, '[');
    if ($start === false) {
        return [];
    }
    $decoded = json_decode(substr($raw, $start), true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Parse the tweet "created_at" format ("Wed Jul 01 18:17:44 +0000 2026") into a
 * 'Y-m-d H:i:s' string in the configured local timezone, or null if unparseable.
 */
function parse_tweet_date(?string $value): ?string
{
    if (! is_string($value) || $value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('D M d H:i:s O Y', $value);
    if ($dt === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $dt->getTimestamp());
}

/**
 * Parse an ISO-8601 DM timestamp ("2019-08-18T17:47:29.189Z") into a
 * 'Y-m-d H:i:s' string in the configured local timezone, or null.
 */
function parse_iso_date(?string $value): ?string
{
    if (! is_string($value) || $value === '') {
        return null;
    }
    $ts = strtotime($value);

    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

$env = load_env($root.'/.env');

date_default_timezone_set($env['APP_TIMEZONE'] ?? 'UTC');

// Quick end-to-end check of the alert channels: `php import_archive.php test-alert`.
if (in_array($argv[1] ?? '', ['test-alert', '--test-alert', 'test'], true)) {
    $stamp = date('Y-m-d H:i:s');
    $results = send_alert(
        $env,
        'SimpleSpyLogger twitter importer: test alert',
        "This is a test alert sent on $stamp to verify email and Discord delivery."
    );
    foreach ($results as $channel => $ok) {
        echo str_pad($channel, 8).': '.($ok ? 'sent' : 'not sent (disabled or failed - see STDERR)')."\n";
    }
    exit(in_array(true, $results, true) ? 0 : 1);
}

if (! class_exists('ZipArchive')) {
    fwrite(STDERR, "The PHP zip extension (ZipArchive) is required but not loaded.\n");
    exit(1);
}

$importDms = env_bool($env, 'TWITTER_IMPORT_DMS', true);

// Archives to import: explicit CLI args win, otherwise every *.zip in this
// folder (the account exports live alongside this script and are gitignored).
$archives = array_values(array_filter(array_slice($argv, 1), static fn ($a) => $a !== ''));
if (! $archives) {
    $archives = glob($root.'/*.zip') ?: [];
}
if (! $archives) {
    fwrite(STDERR, "No archives to import. Pass zip paths as arguments or drop the exports in $root.\n");
    exit(1);
}

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
    'SELECT id, content, payload, source_edited_at
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
            source_edited_at = :source_edited_at,
            updated_at = :updated_at
        WHERE id = :id'
);

// Preload the id -> username map so a DM's channel_name can name the partner we
// messaged instead of a generic "DM". resolve_users.php fills external_users via
// paid API lookups; until a partner id is resolved we fall back to their id.
$externalUsernames = load_twitter_username_map($pdo);

/**
 * Upsert one already-mapped message row. Mirrors the reddit scraper: insert when
 * new, and when an existing row's body changed, snapshot the old body into
 * message_revisions before updating. Returns 'inserted', 'updated', or 'unchanged'.
 */
$upsert = static function (array $row) use ($pdo, $lookup, $insert, $revision, $update): string {
    static $now = null;
    if ($now === null) {
        $now = date('Y-m-d H:i:s');
    }
    $payloadJson = json_encode($row['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $lookup->execute([':source' => 'twitter', ':external_id' => $row['external_id']]);
    $existing = $lookup->fetch(PDO::FETCH_ASSOC);

    if ($existing === false) {
        $insert->execute([
            ':source' => 'twitter',
            ':external_id' => $row['external_id'],
            ':container_external_id' => $row['container_external_id'],
            ':container_name' => $row['container_name'],
            ':channel_external_id' => $row['channel_external_id'],
            ':channel_name' => $row['channel_name'],
            ':visibility' => $row['visibility'],
            ':author_external_id' => $row['author_external_id'],
            ':author_username' => $row['author_username'],
            ':author_display_name' => $row['author_display_name'],
            ':author_bot' => 0,
            ':content' => $row['content'],
            ':referenced_external_id' => $row['referenced_external_id'],
            ':sent_at' => $row['sent_at'],
            ':source_edited_at' => null,
            ':deleted_at' => null,
            ':captured_at' => $now,
            ':payload' => $payloadJson,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return $insert->rowCount() > 0 ? 'inserted' : 'unchanged';
    }

    if ($row['content'] !== null && $row['content'] !== $existing['content']) {
        $pdo->beginTransaction();
        try {
            $revision->execute([
                ':message_id' => $existing['id'],
                ':content' => $existing['content'],
                ':payload' => $existing['payload'],
                ':source_edited_at' => $existing['source_edited_at'],
                ':captured_at' => $now,
                ':created_at' => $now,
            ]);
            $update->execute([
                ':id' => $existing['id'],
                ':content' => $row['content'],
                ':payload' => $payloadJson,
                ':source_edited_at' => $existing['source_edited_at'],
                ':updated_at' => $now,
            ]);
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        return 'updated';
    }

    return 'unchanged';
};

$totalInserted = 0;
$totalUpdated = 0;
$failures = [];

foreach ($archives as $archivePath) {
    if (! is_file($archivePath)) {
        fwrite(STDERR, "Archive not found: $archivePath\n");
        $failures[] = ['archive' => $archivePath, 'error' => 'file not found'];
        continue;
    }

    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        fwrite(STDERR, "Could not open zip: $archivePath\n");
        $failures[] = ['archive' => $archivePath, 'error' => 'could not open zip'];
        continue;
    }

    // Owner identity from account.js: the account that this export belongs to.
    $accounts = decode_ytd($zip->getFromName('data/account.js') ?: null);
    $account = $accounts[0]['account'] ?? null;
    if (! is_array($account) || empty($account['accountId'])) {
        fwrite(STDERR, "No usable account.js in $archivePath; skipping.\n");
        $failures[] = ['archive' => $archivePath, 'error' => 'missing/invalid account.js'];
        $zip->close();
        continue;
    }
    $ownerId = (string) $account['accountId'];
    $ownerUsername = (string) ($account['username'] ?? '');
    $ownerDisplay = $account['accountDisplayName'] ?? null;
    $label = $ownerUsername !== '' ? '@'.$ownerUsername : $ownerId;

    // note-tweet.js holds the untruncated body of long tweets. tweets.js stores
    // those truncated with a trailing ellipsis, and there is no id linking the
    // two, so index the note bodies by their created-to-the-second timestamp;
    // the matching tweet shares that exact second (verified against real
    // exports). Multiple notes in the same second are disambiguated by prefix.
    $notesBySecond = [];
    foreach (decode_ytd($zip->getFromName('data/note-tweet.js') ?: null) as $wrap) {
        $note = $wrap['noteTweet'] ?? null;
        $text = $note['core']['text'] ?? null;
        $created = $note['createdAt'] ?? null;
        if (! is_string($text) || ! is_string($created)) {
            continue;
        }
        // Note createdAt is ISO ("2025-07-30T17:37:46.000Z"); key on the second.
        $sec = substr($created, 0, 19);
        $notesBySecond[$sec][] = $text;
    }

    $tweets = decode_ytd($zip->getFromName('data/tweets.js') ?: null);
    $insertedForAcct = 0;
    $updatedForAcct = 0;

    foreach ($tweets as $wrap) {
        $t = $wrap['tweet'] ?? null;
        if (! is_array($t) || empty($t['id_str'])) {
            continue;
        }

        $fullText = (string) ($t['full_text'] ?? '');
        $isNote = false;

        // Long tweet: tweets.js truncates the body with a trailing ellipsis.
        // Swap in the full note body when we can match one for this exact second.
        if (substr($fullText, -3) === "\xE2\x80\xA6" && isset($t['created_at'])) {
            $dt = DateTime::createFromFormat('D M d H:i:s O Y', $t['created_at']);
            if ($dt !== false) {
                $sec = gmdate('Y-m-d\TH:i:s', $dt->getTimestamp());
                // The tweet body may be prefixed with reply @mentions that the
                // note body omits, so strip that prefix first, then match the
                // start of the (truncated) tweet body against the note body.
                $body = ltrim(preg_replace('/^(@\w+\s+)+/', '', $fullText));
                $needle = trim(rtrim($body, "\xE2\x80\xA6"));
                $needle = mb_substr($needle, 0, 40);
                foreach ($notesBySecond[$sec] ?? [] as $noteText) {
                    if ($needle !== '' && mb_strpos($noteText, $needle) === 0) {
                        $fullText = $noteText;
                        $isNote = true;
                        break;
                    }
                }
            }
        }

        $isRetweet = strncmp($fullText, 'RT @', 4) === 0;
        $replyTo = isset($t['in_reply_to_status_id_str']) && $t['in_reply_to_status_id_str'] !== ''
            ? (string) $t['in_reply_to_status_id_str']
            : null;

        // Quote tweet: a self t.co link expanding to a twitter/x status URL.
        $quotedId = null;
        foreach ($t['entities']['urls'] ?? [] as $u) {
            $expanded = $u['expanded_url'] ?? ($u['expanded'] ?? '');
            if (preg_match('#(?:twitter|x)\.com/[^/]+/status/(\d+)#', (string) $expanded, $m)) {
                $quotedId = $m[1];
                break;
            }
        }

        if ($isRetweet) {
            $kind = 'retweet';
        } elseif ($replyTo !== null) {
            $kind = 'reply';
        } elseif ($quotedId !== null) {
            $kind = 'quote';
        } else {
            $kind = 'tweet';
        }

        $urls = [];
        foreach ($t['entities']['urls'] ?? [] as $u) {
            $exp = $u['expanded_url'] ?? ($u['expanded'] ?? null);
            if (is_string($exp) && $exp !== '') {
                $urls[] = $exp;
            }
        }
        $mentions = [];
        foreach ($t['entities']['user_mentions'] ?? [] as $mn) {
            if (! empty($mn['screen_name'])) {
                $mentions[] = $mn['screen_name'];
            }
        }

        $payload = [
            'kind' => $kind,
            'metrics' => [
                'likes' => (int) ($t['favorite_count'] ?? 0),
                'retweets' => (int) ($t['retweet_count'] ?? 0),
            ],
            'lang' => $t['lang'] ?? null,
            'client' => isset($t['source']) ? strip_tags((string) $t['source']) : null,
            'mentions' => $mentions,
            'urls' => $urls,
        ];
        if ($replyTo !== null) {
            $payload['in_reply_to_screen_name'] = $t['in_reply_to_screen_name'] ?? null;
        }
        if ($quotedId !== null) {
            $payload['quoted_status_id'] = $quotedId;
        }
        if ($isNote) {
            $payload['is_note'] = true;
        }

        try {
            $result = $upsert([
                'external_id' => (string) $t['id_str'],
                'container_external_id' => $ownerId,
                'container_name' => $ownerUsername !== '' ? $ownerUsername : null,
                'channel_external_id' => null,
                'channel_name' => null,
                'visibility' => 'public',
                'author_external_id' => $ownerId,
                'author_username' => $ownerUsername !== '' ? $ownerUsername : null,
                'author_display_name' => $ownerDisplay,
                'content' => $fullText,
                'referenced_external_id' => $replyTo,
                'sent_at' => parse_tweet_date($t['created_at'] ?? null),
                'payload' => $payload,
            ]);
            if ($result === 'inserted') {
                $insertedForAcct++;
            } elseif ($result === 'updated') {
                $updatedForAcct++;
            }
        } catch (PDOException $e) {
            fwrite(STDERR, "Skipped tweet {$t['id_str']}: ".$e->getMessage()."\n");
        }
    }

    echo "Twitter: $label - ".count($tweets)." tweets read, $insertedForAcct new, $updatedForAcct edited.\n";

    // Direct messages (both 1:1 and group). Each conversation carries a list of
    // events; only messageCreate events are stored. A DM appears in both
    // participants' archives with the same id, so INSERT IGNORE dedupes across
    // accounts and the first archive to import it wins the owner bucket.
    if ($importDms) {
        $dmSources = [
            ['data/direct-messages.js', false],
            ['data/direct-messages-group.js', true],
        ];
        $dmInserted = 0;
        foreach ($dmSources as [$name, $isGroup]) {
            foreach (decode_ytd($zip->getFromName($name) ?: null) as $wrap) {
                $conv = $wrap['dmConversation'] ?? null;
                if (! is_array($conv)) {
                    continue;
                }
                $conversationId = (string) ($conv['conversationId'] ?? '');
                foreach ($conv['messages'] ?? [] as $ev) {
                    $m = $ev['messageCreate'] ?? null;
                    if (! is_array($m) || empty($m['id'])) {
                        continue;
                    }
                    $senderId = (string) ($m['senderId'] ?? '');
                    $urls = [];
                    foreach ($m['urls'] ?? [] as $u) {
                        if (! empty($u['expanded'])) {
                            $urls[] = $u['expanded'];
                        }
                    }
                    // The conversation partner (the person we messaged), so the
                    // DM's channel names who it is with rather than a bare "DM".
                    $partnerId = dm_partner_id($conversationId, $ownerId, $isGroup);
                    $channelName = dm_channel_name($partnerId, $isGroup, $externalUsernames);

                    $payload = [
                        'kind' => 'dm',
                        'conversation_id' => $conversationId,
                        'group' => $isGroup,
                        'partner_external_id' => $partnerId,
                        'urls' => $urls,
                        'media' => $m['mediaUrls'] ?? [],
                    ];
                    if (! $isGroup && ! empty($m['recipientId'])) {
                        $payload['recipient_id'] = (string) $m['recipientId'];
                    }

                    try {
                        $result = $upsert([
                            'external_id' => (string) $m['id'],
                            'container_external_id' => $ownerId,
                            'container_name' => $ownerUsername !== '' ? $ownerUsername : null,
                            'channel_external_id' => $conversationId !== '' ? $conversationId : null,
                            'channel_name' => $channelName,
                            'visibility' => 'private',
                            'author_external_id' => $senderId !== '' ? $senderId : null,
                            // Only the owner's username is known from the export;
                            // other participants are ids only.
                            'author_username' => ($senderId === $ownerId && $ownerUsername !== '') ? $ownerUsername : null,
                            'author_display_name' => ($senderId === $ownerId) ? $ownerDisplay : null,
                            'content' => (string) ($m['text'] ?? ''),
                            'referenced_external_id' => null,
                            'sent_at' => parse_iso_date($m['createdAt'] ?? null),
                            'payload' => $payload,
                        ]);
                        if ($result === 'inserted') {
                            $dmInserted++;
                        }
                    } catch (PDOException $e) {
                        fwrite(STDERR, "Skipped DM {$m['id']}: ".$e->getMessage()."\n");
                    }
                }
            }
        }
        echo "Twitter: $label - $dmInserted new direct messages.\n";
        $insertedForAcct += $dmInserted;
    }

    $totalInserted += $insertedForAcct;
    $totalUpdated += $updatedForAcct;
    $zip->close();
}

$summary = "Twitter archive import: inserted $totalInserted new, $totalUpdated edited across ".count($archives)." archive(s).";
echo $summary."\n";
log_import_summary($summary);

if ($failures) {
    $lines = [count($failures).' archive(s) failed to import:', ''];
    foreach ($failures as $f) {
        $lines[] = $f['archive'].': '.$f['error'];
    }
    send_alert($env, 'SimpleSpyLogger twitter importer: '.count($failures).' archive(s) failed', implode("\n", $lines));
    exit(1);
}
