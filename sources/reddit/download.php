<?php
/**
 * SimpleSpyLogger - Reddit comment scraper
 *
 * Fetches the latest comments for the configured Reddit user(s) via the
 * JSON endpoint. As of 2026 Reddit returns HTTP 403 for anonymous requests
 * to these endpoints, so an authenticated browser session cookie
 * (REDDIT_SESSION_COOKIE) is required. Recommended to run no more than twice
 * a day to stay polite with Reddit's rate limits.
 *
 * Endpoints available by Reddit:
 * https://www.reddit.com/user/username.json
 * https://www.reddit.com/user/username.rss
 *
 * A Reddit comment is mapped as: subreddit -> container, post -> channel,
 * commenter -> author.
 *
 * Single request per user per run, returning the most recent
 * REDDIT_PER_PAGE comments (up to 100). No pagination; full historical
 * backfill beyond the first page is not supported here.
 *
 * Usage:
 *   php download.php            normal run (randomized 60s-38m start delay)
 *   php download.php no-delay   fetch immediately, skip all delays (manual/test)
 *
 * Cron: before each run this scraper auto-refreshes REDDIT_SESSION_COOKIE from
 * the live Chrome profile (see refresh_cookie.php). That has to read the login
 * keyring (Secret Service / gnome-keyring), which a bare cron job cannot reach
 * because cron has no desktop-session environment. So the cron line MUST export
 * the user D-Bus session, and it must be a single line:
 *
 *   0 0,12 * * * XDG_RUNTIME_DIR=/run/user/1000 DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/1000/bus /usr/bin/php /path/to/sources/reddit/download.php >/dev/null 2>&1
 *
 * Replace 1000 with your numeric uid (run `id -u`). The keyring also has to be
 * unlocked, i.e. you must be logged into the desktop session (it stays unlocked
 * through screen-lock and only locks on full logout). If the keyring is locked
 * or unreachable, the refresh is skipped and the scraper falls back to the
 * cookie already stored in .env.
 */

$root = __DIR__;

require_once __DIR__.'/../notifier.php';

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

function http_get(string $url, string $userAgent, string $cookie = ''): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => $userAgent,
    ];
    if ($cookie !== '') {
        $opts[CURLOPT_COOKIE] = $cookie;
    }
    curl_setopt_array($ch, $opts);
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

$env = load_env($root.'/.env');

date_default_timezone_set($env['APP_TIMEZONE'] ?? 'UTC');

// Quick end-to-end check of the alert channels without scraping or touching
// the DB: `php download.php test-alert`. Fires send_alert() and reports which
// channels delivered, so you can confirm email/Discord config before relying
// on the failure alerts.
if (in_array($argv[1] ?? '', ['test-alert', '--test-alert', 'test'], true)) {
    $stamp = date('Y-m-d H:i:s');
    $results = send_alert(
        $env,
        'SimpleSpyLogger reddit scraper: test alert',
        "This is a test alert sent on $stamp to verify email and Discord delivery."
    );
    foreach ($results as $channel => $ok) {
        echo str_pad($channel, 8).': '.($ok ? 'sent' : 'not sent (disabled or failed - see STDERR)')."\n";
    }
    exit(in_array(true, $results, true) ? 0 : 1);
}

// Override for manual/test runs: skip the randomized human-like delays so the
// scraper fetches immediately. Usage: php download.php no-delay
$skipDelay = in_array($argv[1] ?? '', ['no-delay', '--no-delay', 'now'], true);

$userAgent = $env['REDDIT_USER_AGENT'] ?? 'SimpleSpyLogger-RedditScraper/1.0';

// Reddit rotates the reddit_session cookie on every browser login and revokes
// the previous one, so refresh it from the live Chrome profile before fetching.
// Best-effort: falls back to the cookie stored in .env if Chrome is closed mid
// write, the keyring is locked (logged out), or the profile/cookie is missing.
require __DIR__.'/refresh_cookie.php';
$freshCookie = refreshRedditSessionCookie($root.'/.env', $env);
if ($freshCookie !== null && $freshCookie !== '') {
    $env['REDDIT_SESSION_COOKIE'] = $freshCookie;
}

// Reddit now 403s anonymous requests to the JSON endpoints, so we replay an
// authenticated browser session cookie. Accept either a bare reddit_session
// value or a full "name=value; name2=value2" Cookie header; the bare value is
// the common case so wrap it into reddit_session=... when no '=' is present.
$sessionCookie = trim($env['REDDIT_SESSION_COOKIE'] ?? '');
if ($sessionCookie !== '' && strpos($sessionCookie, '=') === false) {
    $sessionCookie = 'reddit_session='.$sessionCookie;
}
if ($sessionCookie === '') {
    fwrite(STDERR, "Warning: REDDIT_SESSION_COOKIE is empty; Reddit requires an authenticated session and will likely return HTTP 403.\n");
}

$usernames = array_filter(array_map('trim', explode(',', $env['REDDIT_TARGET_USERNAMES'] ?? '')));
$perPage = (int) ($env['REDDIT_PER_PAGE'] ?? 100);
if ($perPage < 1 || $perPage > 100) {
    $perPage = 100;
}

if (! $usernames) {
    fwrite(STDERR, "REDDIT_TARGET_USERNAMES must be set in .env\n");
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
            deleted_at = COALESCE(deleted_at, :deleted_at),
            updated_at = :updated_at
        WHERE id = :id'
);

// Randomize the start within the cron window so requests don't land on the
// minute hand. Range: 60s - 38m.
$startDelay = $skipDelay ? 0 : random_int(60, 38 * 60);
echo $skipDelay ? "Reddit: skipping start delay (no-delay override).\n" : "Reddit: waiting {$startDelay}s before first fetch.\n";
sleep($startDelay);

$capturedAt = date('Y-m-d H:i:s');
$host = 'reddit.com';
$totalInserted = 0;
$totalUpdated = 0;
$userIndex = 0;
$failures = [];

foreach ($usernames as $username) {
    if ($userIndex++ > 0 && ! $skipDelay) {
        // Random pause between users (0-120s) so the request pattern doesn't
        // look like a bot hammering through accounts back-to-back.
        $duration = random_int(0, 120);
        echo "Reddit: pausing {$duration}s before next user.\n";
        sleep($duration);
    }

    $url = 'https://www.reddit.com/user/'.urlencode($username)
        .'/comments.json?limit='.$perPage.'&sort=new&raw_json=1';

    $resp = http_get($url, $userAgent, $sessionCookie);
    if (! $resp['ok']) {
        $err = $resp['error'] !== null ? 'curl: '.$resp['error'] : 'HTTP '.$resp['code'];
        fwrite(STDERR, "$err for $url\n");
        $failures[] = [
            'username' => $username,
            'url' => $url,
            'error' => $err,
            'body_excerpt' => $resp['body'] !== null ? mb_substr((string) $resp['body'], 0, 300) : '',
        ];
        continue;
    }

    $data = json_decode($resp['body'], true);
    if (! is_array($data)) {
        fwrite(STDERR, "JSON parse failure for $url\n");
        $failures[] = [
            'username' => $username,
            'url' => $url,
            'error' => 'JSON parse failure',
            'body_excerpt' => mb_substr((string) $resp['body'], 0, 300),
        ];
        continue;
    }
    $children = $data['data']['children'] ?? [];
    if (! is_array($children) || ! $children) {
        echo "Reddit: $username - 0 comments fetched, 0 new.\n";
        continue;
    }

    $fetched = 0;
    $insertedForUser = 0;
    $updatedForUser = 0;

    foreach ($children as $child) {
        if (($child['kind'] ?? null) !== 't1' || empty($child['data']['id'])) {
            continue;
        }
        $c = $child['data'];
        $fetched++;

        $externalId = $host.':t1_'.$c['id'];

        $parentId = $c['parent_id'] ?? null;
        $referenced = null;
        if (is_string($parentId) && strpos($parentId, 't1_') === 0) {
            $referenced = $host.':'.$parentId;
        }

        $editedAt = null;
        if (isset($c['edited']) && is_numeric($c['edited'])) {
            $editedAt = date('Y-m-d H:i:s', (int) $c['edited']);
        }

        $isDeleted = (($c['author'] ?? '') === '[deleted]')
            || in_array($c['body'] ?? '', ['[deleted]', '[removed]'], true);

        $payload = json_encode([
            'permalink' => $c['permalink'] ?? null,
            'score' => $c['score'] ?? null,
            'controversiality' => $c['controversiality'] ?? null,
            'distinguished' => $c['distinguished'] ?? null,
            'stickied' => $c['stickied'] ?? null,
            'subreddit_type' => $c['subreddit_type'] ?? null,
            'subreddit_id' => $c['subreddit_id'] ?? null,
            'link_id' => $c['link_id'] ?? null,
            'link_permalink' => $c['link_permalink'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $newContent = $c['body'] ?? null;
        $deletedAt = $isDeleted ? $capturedAt : null;

        try {
            $lookup->execute([':source' => 'reddit', ':external_id' => $externalId]);
            $existing = $lookup->fetch(PDO::FETCH_ASSOC);

            if ($existing === false) {
                $insert->execute([
                    ':source' => 'reddit',
                    ':external_id' => $externalId,
                    ':container_external_id' => $c['subreddit_id'] ?? null,
                    ':container_name' => $c['subreddit'] ?? null,
                    ':channel_external_id' => $c['link_id'] ?? null,
                    ':channel_name' => $c['link_title'] ?? null,
                    ':visibility' => 'public',
                    ':author_external_id' => $c['author_fullname'] ?? null,
                    ':author_username' => (string) ($c['author'] ?? ''),
                    ':author_display_name' => null,
                    ':author_bot' => 0,
                    ':content' => $newContent,
                    ':referenced_external_id' => $referenced,
                    ':sent_at' => isset($c['created_utc']) ? date('Y-m-d H:i:s', (int) $c['created_utc']) : null,
                    ':source_edited_at' => $editedAt,
                    ':deleted_at' => $deletedAt,
                    ':captured_at' => $capturedAt,
                    ':payload' => $payload,
                    ':created_at' => $capturedAt,
                    ':updated_at' => $capturedAt,
                ]);
                $insertedForUser += $insert->rowCount();
            } elseif ($newContent !== null && $newContent !== $existing['content']) {
                $pdo->beginTransaction();
                try {
                    $revision->execute([
                        ':message_id' => $existing['id'],
                        ':content' => $existing['content'],
                        ':payload' => $existing['payload'],
                        ':source_edited_at' => $existing['source_edited_at'],
                        ':captured_at' => $capturedAt,
                        ':created_at' => $capturedAt,
                    ]);
                    $update->execute([
                        ':id' => $existing['id'],
                        ':content' => $newContent,
                        ':payload' => $payload,
                        ':source_edited_at' => $editedAt,
                        ':deleted_at' => $deletedAt,
                        ':updated_at' => $capturedAt,
                    ]);
                    $pdo->commit();
                    $updatedForUser++;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
        } catch (PDOException $e) {
            fwrite(STDERR, "Skipped comment {$c['id']}: ".$e->getMessage()."\n");
        }
    }

    echo "Reddit: $username - $fetched comments fetched, $insertedForUser new, $updatedForUser edited.\n";
    $totalInserted += $insertedForUser;
    $totalUpdated += $updatedForUser;
}

$summary = "Reddit: inserted $totalInserted new comments, $totalUpdated edited total.";
echo $summary."\n";
log_import_summary($summary);

if ($failures) {
    $total = count($usernames);
    $failed = count($failures);
    $subject = "SimpleSpyLogger reddit scraper: $failed/$total users failed to fetch";
    $lines = ["$failed of $total users failed to fetch on $capturedAt:", ''];
    // A run of 403s almost always means the authenticated session cookie has
    // expired or been revoked; call that out so the fix is obvious.
    $has403 = false;
    foreach ($failures as $f) {
        if (strpos($f['error'], 'HTTP 403') !== false) {
            $has403 = true;
            break;
        }
    }
    if ($has403) {
        $lines[] = 'Hint: HTTP 403 usually means REDDIT_SESSION_COOKIE has expired or been revoked. Grab a fresh reddit_session cookie from a logged-in browser and update .env.';
        $lines[] = '';
    }
    foreach ($failures as $f) {
        $lines[] = $f['username'].':';
        $lines[] = '  URL:   '.$f['url'];
        $lines[] = '  Error: '.$f['error'];
        if ($f['body_excerpt'] !== '') {
            $lines[] = '  Body excerpt: '.preg_replace('/\s+/', ' ', $f['body_excerpt']);
        }
        $lines[] = '';
    }
    send_alert($env, $subject, implode("\n", $lines));
    exit($failed === $total ? 1 : 0);
}
