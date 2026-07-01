<?php
/**
 * SimpleSpyLogger - Twitter/X incremental scraper (API top-up).
 *
 * Pulls new tweets (and optionally DMs) for each configured account via the X
 * API v2 and upserts them into the messages table. Runs after
 * import_archive.php has backfilled history; it only fetches what is newer than
 * what we already have, so a run costs one read per genuinely new tweet.
 *
 * Auth: OAuth 2.0 Authorization Code with PKCE, read-only scopes (tweet.read,
 * users.read, dm.read, offline.access). This reads tweets AND DMs without any
 * write access. Each account is authorized once via oauth2_authorize.php, which
 * stores a refresh token in .env; this scraper exchanges that refresh token for
 * a short-lived access token at the start of each account's run. X rotates
 * refresh tokens, so the new one is persisted back to .env immediately.
 *
 * Configure accounts as TWITTER_A1_*, TWITTER_A2_*, ... in .env (gaps allowed,
 * any number). Each needs USER_ID, CLIENT_ID, CLIENT_SECRET and a REFRESH_TOKEN
 * (produced by oauth2_authorize.php).
 *
 * Incremental strategy:
 *   Tweets - GET /2/users/:id/tweets with since_id set to the newest tweet id
 *            we already stored, so the API returns only newer tweets (newest
 *            first), paginated up to TWITTER_MAX_TWEETS_PER_RUN.
 *   DMs    - GET /2/dm_events (newest first); paginate until we reach an event
 *            id already in the DB, then stop. The archive import covers older DMs.
 *
 * The upsert is identical to import_archive.php: existing rows are skipped (or
 * revision-tracked if the body changed), never duplicated.
 *
 * Usage:
 *   php download.php            normal run (randomized start delay)
 *   php download.php no-delay   fetch immediately, skip delays (manual/test)
 *   php download.php test-alert fire a test alert and exit
 *
 * Cron (once or twice a day is plenty; reads are billed per tweet):
 *   0 6 * * * /usr/bin/php /path/to/sources/twitter/download.php >> /path/to/sources/twitter/cron.log 2>&1
 */

$root = __DIR__;

$cronLog = (string) (getenv('TWITTER_CRON_LOG') ?: '');
if ($cronLog !== '') {
    echo '=== twitter '.date('Y-m-d H:i:s O')." ===\n";
    register_shutdown_function(static function () use ($cronLog) {
        $maxLines = (int) (getenv('TWITTER_CRON_LOG_MAX_LINES') ?: 2000);
        if ($maxLines < 1) {
            return;
        }
        if (is_resource(STDOUT)) {
            fflush(STDOUT);
        }
        if (is_resource(STDERR)) {
            fflush(STDERR);
        }
        if (! is_file($cronLog)) {
            return;
        }
        $lines = file($cronLog, FILE_IGNORE_NEW_LINES);
        if ($lines === false || count($lines) <= $maxLines) {
            return;
        }
        $tmp = $cronLog.'.tmp';
        if (file_put_contents($tmp, implode("\n", array_slice($lines, -$maxLines))."\n", LOCK_EX) !== false) {
            rename($tmp, $cronLog);
        }
    });
}

require_once __DIR__.'/../notifier.php';
require_once __DIR__.'/oauth2.php';
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
 * GET an X API v2 endpoint with an OAuth 2.0 bearer access token.
 * Returns ['ok','code','body','error'].
 */
function api_get(string $baseUrl, array $queryParams, string $accessToken): array
{
    $url = $baseUrl.($queryParams ? '?'.http_build_query($queryParams) : '');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$accessToken],
        CURLOPT_USERAGENT => 'SimpleSpyLogger-TwitterScraper/1.0',
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);

        return ['ok' => false, 'code' => 0, 'body' => null, 'error' => $err];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['ok' => $code < 400, 'code' => $code, 'body' => (string) $resp, 'error' => null];
}

$envPath = $root.'/.env';
$env = load_env($envPath);
date_default_timezone_set($env['APP_TIMEZONE'] ?? 'UTC');

if (in_array($argv[1] ?? '', ['test-alert', '--test-alert', 'test'], true)) {
    $stamp = date('Y-m-d H:i:s');
    $results = send_alert(
        $env,
        'SimpleSpyLogger twitter scraper: test alert',
        "This is a test alert sent on $stamp to verify email and Discord delivery."
    );
    foreach ($results as $channel => $ok) {
        echo str_pad($channel, 8).': '.($ok ? 'sent' : 'not sent (disabled or failed - see STDERR)')."\n";
    }
    exit(in_array(true, $results, true) ? 0 : 1);
}

$skipDelay = in_array($argv[1] ?? '', ['no-delay', '--no-delay', 'now'], true);
$scrapeDms = env_bool($env, 'TWITTER_SCRAPE_DMS', true);
$maxTweets = max(1, (int) ($env['TWITTER_MAX_TWEETS_PER_RUN'] ?? 100));

// DM events fetched per page. The DM endpoint has no since_id, so each run must
// read the newest page to detect new DMs and is billed per event returned. A
// smaller page caps that "just checking" cost; new DMs beyond one page are
// picked up by pagination. Range 1-100.
$dmMaxResults = max(1, min(100, (int) ($env['TWITTER_DM_MAX_RESULTS'] ?? 15)));

// Per-resource read prices for the estimated-cost line in the logs (X
// pay-per-use). Overridable in case X changes pricing. Reading our own posts via
// GET /2/users/{id}/tweets qualifies for X's "Owned Read" rate of $0.001 per
// resource (not the standard $0.005), because {id} is the authenticated app
// owner reading their own data. DM events are not an Owned Read, so they stay at
// $0.010.
$costPerTweet = (float) ($env['TWITTER_COST_PER_TWEET_READ'] ?? 0.001);
$costPerDm = (float) ($env['TWITTER_COST_PER_DM_READ'] ?? 0.010);

// Dedup-aware read ledger. X does not bill for re-reading the same resource
// within a UTC day, so to make the logged cost match the actual bill we record
// which resource ids we have already counted today (UTC) and only bill the first
// read of each id per day. The ledger is a small JSON file that resets itself at
// UTC midnight (when today's date no longer matches the stored one).
$ledgerPath = $root.'/.read_ledger.json';
$todayUtc = gmdate('Y-m-d');
$ledger = [];
if (is_file($ledgerPath)) {
    $decoded = json_decode((string) file_get_contents($ledgerPath), true);
    if (is_array($decoded) && ($decoded['utc_date'] ?? null) === $todayUtc && is_array($decoded['ids'] ?? null)) {
        $ledger = array_fill_keys($decoded['ids'], true);
    }
}
// Returns true the first time an id is seen today (a billable read), false on
// any repeat within the same UTC day (deduplicated, not billed).
$billable = static function (string $id) use (&$ledger): bool {
    if ($id === '' || isset($ledger[$id])) {
        return false;
    }
    $ledger[$id] = true;

    return true;
};

// Collect configured accounts from any TWITTER_A{n}_USERNAME slots. Gaps are
// tolerated: indices need not be contiguous (e.g. A1 and A3 with A2 removed both
// still work), and there is no upper bound. Slots with an empty username are
// ignored; slots are processed in ascending numeric order.
$accountIndexes = [];
foreach (array_keys($env) as $key) {
    if (preg_match('/^TWITTER_A(\d+)_USERNAME$/', $key, $m) && trim($env[$key]) !== '') {
        $accountIndexes[] = (int) $m[1];
    }
}
sort($accountIndexes, SORT_NUMERIC);

$accounts = [];
foreach ($accountIndexes as $i) {
    $p = "TWITTER_A{$i}_";
    $accounts[] = [
        'slot' => $i,
        'username' => trim($env[$p.'USERNAME']),
        'user_id' => trim($env[$p.'USER_ID'] ?? ''),
        'client_id' => trim($env[$p.'CLIENT_ID'] ?? ''),
        'client_secret' => trim($env[$p.'CLIENT_SECRET'] ?? ''),
        'refresh_token' => trim($env[$p.'REFRESH_TOKEN'] ?? ''),
    ];
}

if (! $accounts) {
    fwrite(STDERR, "No accounts configured (set TWITTER_A1_USERNAME etc. in .env).\n");
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
$newestTweet = $pdo->prepare(
    "SELECT MAX(CAST(external_id AS UNSIGNED)) AS max_id
       FROM messages
       WHERE source = 'twitter' AND container_external_id = :owner AND visibility = 'public'"
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
        SET content = :content, payload = :payload,
            source_edited_at = :source_edited_at, updated_at = :updated_at
        WHERE id = :id'
);

// id -> username map so a new DM's channel_name names the partner (filled by
// resolve_users.php; unresolved partners fall back to their id).
$externalUsernames = load_twitter_username_map($pdo);

// Same skip/update-or-insert upsert as import_archive.php. Returns
// 'inserted', 'updated', or 'exists' (row already present, so DM pagination
// knows it has caught up).
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

        return $insert->rowCount() > 0 ? 'inserted' : 'exists';
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

    return 'exists';
};

// Randomized start so cron runs don't hit the API on the exact minute.
$startDelay = $skipDelay ? 0 : random_int(30, 20 * 60);
echo $skipDelay ? "Twitter: skipping start delay (no-delay override).\n" : "Twitter: waiting {$startDelay}s before first fetch.\n";
sleep($startDelay);

$totalInserted = 0;
$totalUpdated = 0;
$totalTweetsRead = 0;
$totalDmRead = 0;
$totalTweetsBilled = 0;
$totalDmBilled = 0;
$failures = [];
$accountIndex = 0;

foreach ($accounts as $acct) {
    $label = '@'.$acct['username'];
    $ownerId = $acct['user_id'];

    if ($acct['client_id'] === '' || $acct['client_secret'] === '' || $acct['refresh_token'] === '') {
        fwrite(STDERR, "$label: missing OAuth 2.0 config; run `php oauth2_authorize.php A{$acct['slot']}` first.\n");
        $failures[] = ['account' => $acct['username'], 'error' => 'missing OAuth 2.0 config (run oauth2_authorize.php)'];
        continue;
    }
    if ($ownerId === '') {
        fwrite(STDERR, "$label: missing TWITTER_A{$acct['slot']}_USER_ID; skipping.\n");
        $failures[] = ['account' => $acct['username'], 'error' => 'missing user id'];
        continue;
    }

    if ($accountIndex++ > 0 && ! $skipDelay) {
        $pause = random_int(5, 60);
        echo "Twitter: pausing {$pause}s before next account.\n";
        sleep($pause);
    }

    // Exchange the stored refresh token for a fresh access token. X rotates the
    // refresh token, so persist the new one to .env immediately - the old one is
    // now invalid and next run must use the new one.
    $refresh = twitter_oauth2_refresh($acct['refresh_token'], $acct['client_id'], $acct['client_secret']);
    if (! $refresh['ok'] || empty($refresh['data']['access_token'])) {
        $detail = 'HTTP '.$refresh['code'].($refresh['error'] ? ' '.$refresh['error'] : '');
        fwrite(STDERR, "$label: token refresh failed ($detail): ".mb_substr($refresh['raw'], 0, 200)."\n");
        $failures[] = [
            'account' => $acct['username'],
            'error' => 'token refresh failed: '.$detail,
            'body_excerpt' => mb_substr($refresh['raw'], 0, 300),
        ];
        continue;
    }
    $accessToken = (string) $refresh['data']['access_token'];
    if (! empty($refresh['data']['refresh_token'])) {
        $p = "TWITTER_A{$acct['slot']}_";
        if (! twitter_env_set($envPath, $p.'REFRESH_TOKEN', (string) $refresh['data']['refresh_token'])) {
            fwrite(STDERR, "$label: WARNING - could not persist rotated refresh token to .env; next run may fail.\n");
        }
    }

    // --- Tweets: only what is newer than the newest tweet we already hold. ---
    $newestTweet->execute([':owner' => $ownerId]);
    $sinceId = $newestTweet->fetchColumn();
    $sinceId = ($sinceId !== false && $sinceId !== null) ? (string) $sinceId : null;

    $insertedForAcct = 0;
    $updatedForAcct = 0;
    $fetched = 0;
    $tweetsBilledForAcct = 0;
    $paginationToken = null;
    $tweetFailure = false;

    do {
        $query = [
            'max_results' => (string) min(100, $maxTweets - $fetched),
            'tweet.fields' => 'created_at,public_metrics,entities,referenced_tweets,in_reply_to_user_id,lang,source,conversation_id',
        ];
        if ($sinceId !== null) {
            $query['since_id'] = $sinceId;
        }
        if ($paginationToken !== null) {
            $query['pagination_token'] = $paginationToken;
        }

        $resp = api_get("https://api.twitter.com/2/users/{$ownerId}/tweets", $query, $accessToken);
        if (! $resp['ok']) {
            $err = $resp['error'] !== null ? 'curl: '.$resp['error'] : 'HTTP '.$resp['code'];
            fwrite(STDERR, "$label tweets: $err\n");
            $failures[] = [
                'account' => $acct['username'],
                'error' => 'tweets: '.$err,
                'body_excerpt' => $resp['body'] !== null ? mb_substr((string) $resp['body'], 0, 300) : '',
            ];
            $tweetFailure = true;
            break;
        }

        $data = json_decode($resp['body'], true);
        $tweets = $data['data'] ?? [];
        foreach ($tweets as $t) {
            if (empty($t['id'])) {
                continue;
            }
            $fetched++;
            if ($billable((string) $t['id'])) {
                $tweetsBilledForAcct++;
            }

            $kind = 'tweet';
            $referenced = null;
            $refExtra = [];
            foreach ($t['referenced_tweets'] ?? [] as $ref) {
                switch ($ref['type'] ?? '') {
                    case 'replied_to':
                        $kind = 'reply';
                        $referenced = (string) $ref['id'];
                        break;
                    case 'retweeted':
                        $kind = 'retweet';
                        $refExtra['retweeted_status_id'] = (string) $ref['id'];
                        break;
                    case 'quoted':
                        $kind = 'quote';
                        $refExtra['quoted_status_id'] = (string) $ref['id'];
                        break;
                }
            }

            $mentions = [];
            foreach ($t['entities']['mentions'] ?? [] as $mn) {
                if (! empty($mn['username'])) {
                    $mentions[] = $mn['username'];
                }
            }
            $urls = [];
            foreach ($t['entities']['urls'] ?? [] as $u) {
                if (! empty($u['expanded_url'])) {
                    $urls[] = $u['expanded_url'];
                }
            }

            $payload = array_merge([
                'kind' => $kind,
                'metrics' => [
                    'likes' => (int) ($t['public_metrics']['like_count'] ?? 0),
                    'retweets' => (int) ($t['public_metrics']['retweet_count'] ?? 0),
                ],
                'lang' => $t['lang'] ?? null,
                'client' => $t['source'] ?? null,
                'mentions' => $mentions,
                'urls' => $urls,
            ], $refExtra);

            try {
                $result = $upsert([
                    'external_id' => (string) $t['id'],
                    'container_external_id' => $ownerId,
                    'container_name' => $acct['username'],
                    'channel_external_id' => null,
                    'channel_name' => null,
                    'visibility' => 'public',
                    'author_external_id' => $ownerId,
                    'author_username' => $acct['username'],
                    'author_display_name' => null,
                    'content' => (string) ($t['text'] ?? ''),
                    'referenced_external_id' => $referenced,
                    'sent_at' => isset($t['created_at']) ? date('Y-m-d H:i:s', strtotime($t['created_at'])) : null,
                    'payload' => $payload,
                ]);
                if ($result === 'inserted') {
                    $insertedForAcct++;
                } elseif ($result === 'updated') {
                    $updatedForAcct++;
                }
            } catch (PDOException $e) {
                fwrite(STDERR, "$label: skipped tweet {$t['id']}: ".$e->getMessage()."\n");
            }
        }

        $paginationToken = $data['meta']['next_token'] ?? null;
    } while ($paginationToken !== null && $fetched < $maxTweets);

    echo "Twitter: $label - $fetched tweets fetched, $insertedForAcct new, $updatedForAcct edited.\n";
    $totalInserted += $insertedForAcct;
    $totalUpdated += $updatedForAcct;
    $totalTweetsRead += $fetched;

    // --- DMs: page newest-first, stop once we reach ids we already stored. ---
    $dmRead = 0;
    $dmBilledForAcct = 0;
    if ($scrapeDms && ! $tweetFailure) {
        $dmInserted = 0;
        $dmToken = null;
        $caughtUp = false;
        $dmPages = 0;

        do {
            $query = [
                'max_results' => (string) $dmMaxResults,
                // Only fetch message events. ParticipantsJoin/Leave are billed too
                // if returned but we never store them, so exclude them server-side
                // instead of paying to read and then skip them.
                'event_types' => 'MessageCreate',
                'dm_event.fields' => 'created_at,sender_id,dm_conversation_id,text,event_type,attachments',
            ];
            if ($dmToken !== null) {
                $query['pagination_token'] = $dmToken;
            }

            $resp = api_get('https://api.twitter.com/2/dm_events', $query, $accessToken);
            if (! $resp['ok']) {
                $err = $resp['error'] !== null ? 'curl: '.$resp['error'] : 'HTTP '.$resp['code'];
                fwrite(STDERR, "$label DMs: $err\n");
                $failures[] = [
                    'account' => $acct['username'],
                    'error' => 'dms: '.$err,
                    'body_excerpt' => $resp['body'] !== null ? mb_substr((string) $resp['body'], 0, 300) : '',
                ];
                break;
            }

            $data = json_decode($resp['body'], true);
            $events = $data['data'] ?? [];
            // Every DM event the API returns is billed (DM Event: Read), whether
            // or not it is new to us. Count all returned as gross reads, and mark
            // each as billable only the first time it is seen this UTC day.
            $dmRead += count($events);
            foreach ($events as $ev) {
                $evId = (string) ($ev['id'] ?? '');
                if ($billable($evId)) {
                    $dmBilledForAcct++;
                }
                if (($ev['event_type'] ?? '') !== 'MessageCreate' || $evId === '') {
                    continue;
                }
                $senderId = (string) ($ev['sender_id'] ?? '');
                $conversationId = (string) ($ev['dm_conversation_id'] ?? '');

                // The conversation partner (who we messaged), named in channel_name.
                $partnerId = dm_partner_id($conversationId, $ownerId);
                $channelName = dm_channel_name($partnerId, false, $externalUsernames);

                $payload = [
                    'kind' => 'dm',
                    'conversation_id' => $conversationId,
                    'group' => false,
                    'partner_external_id' => $partnerId,
                ];

                try {
                    $result = $upsert([
                        'external_id' => (string) $ev['id'],
                        'container_external_id' => $ownerId,
                        'container_name' => $acct['username'],
                        'channel_external_id' => $conversationId !== '' ? $conversationId : null,
                        'channel_name' => $channelName,
                        'visibility' => 'private',
                        'author_external_id' => $senderId !== '' ? $senderId : null,
                        'author_username' => $senderId === $ownerId ? $acct['username'] : null,
                        'author_display_name' => null,
                        'content' => (string) ($ev['text'] ?? ''),
                        'referenced_external_id' => null,
                        'sent_at' => isset($ev['created_at']) ? date('Y-m-d H:i:s', strtotime($ev['created_at'])) : null,
                        'payload' => $payload,
                    ]);
                    if ($result === 'inserted') {
                        $dmInserted++;
                    } else {
                        // Already have this event; newest-first means everything
                        // beyond here is older and known, so stop paging.
                        $caughtUp = true;
                    }
                } catch (PDOException $e) {
                    fwrite(STDERR, "$label: skipped DM {$ev['id']}: ".$e->getMessage()."\n");
                }
            }

            $dmToken = $data['meta']['next_token'] ?? null;
            $dmPages++;
        } while ($dmToken !== null && ! $caughtUp && $dmPages < 50);

        echo "Twitter: $label - $dmRead DM events read, $dmInserted new.\n";
        $totalInserted += $dmInserted;
    }

    $totalDmRead += $dmRead;
    $totalTweetsBilled += $tweetsBilledForAcct;
    $totalDmBilled += $dmBilledForAcct;
    $acctCost = $tweetsBilledForAcct * $costPerTweet + $dmBilledForAcct * $costPerDm;
    echo sprintf(
        "Twitter: %s - est. billed cost this run: \$%.3f (%d billable posts x \$%.3f + %d billable DM events x \$%.3f); gross read %d posts, %d DM events (UTC-day repeats deduped/free).\n",
        $label, $acctCost, $tweetsBilledForAcct, $costPerTweet, $dmBilledForAcct, $costPerDm, $fetched, $dmRead
    );
}

$estCost = $totalTweetsBilled * $costPerTweet + $totalDmBilled * $costPerDm;
$summary = sprintf(
    'Twitter scrape: inserted %d new, %d edited across %d account(s); billed %d posts + %d DM events = est. $%.3f (gross read %d posts + %d DM events; UTC-day repeats deduped).',
    $totalInserted, $totalUpdated, count($accounts), $totalTweetsBilled, $totalDmBilled, $estCost, $totalTweetsRead, $totalDmRead
);
echo $summary."\n";
log_import_summary($summary);

// Persist today's read ledger so same-UTC-day re-reads on later runs are counted
// as free (matching X's dedup). Temp file + rename so an interrupted write can't
// corrupt it.
$ledgerTmp = $ledgerPath.'.tmp';
if (file_put_contents($ledgerTmp, json_encode(['utc_date' => $todayUtc, 'ids' => array_keys($ledger)]), LOCK_EX) !== false) {
    rename($ledgerTmp, $ledgerPath);
}

if ($failures) {
    $subject = 'SimpleSpyLogger twitter scraper: '.count($failures).' failure(s)';
    $lines = ['Twitter scraper hit '.count($failures)." failure(s) on ".date('Y-m-d H:i:s').':', ''];
    $hasAuth = false;
    foreach ($failures as $f) {
        if (strpos($f['error'], 'refresh failed') !== false
            || strpos($f['error'], 'HTTP 401') !== false
            || strpos($f['error'], 'HTTP 403') !== false) {
            $hasAuth = true;
        }
    }
    if ($hasAuth) {
        $lines[] = 'Hint: an auth failure (refresh/401/403) usually means the refresh token was revoked or expired, or a scope is missing. Re-authorize with `php oauth2_authorize.php A<n>` and make sure dm.read + offline.access are granted.';
        $lines[] = '';
    }
    foreach ($failures as $f) {
        $lines[] = '@'.$f['account'].': '.$f['error'];
        if (! empty($f['body_excerpt'])) {
            $lines[] = '  Body excerpt: '.preg_replace('/\s+/', ' ', $f['body_excerpt']);
        }
    }
    send_alert($env, $subject, implode("\n", $lines));
    exit(count($failures) >= count($accounts) ? 1 : 0);
}
