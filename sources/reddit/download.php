<?php
/**
 * SimpleSpyLogger - Reddit comment scraper
 *
 * Fetches the latest comments for the configured Reddit user(s) via the
 * public JSON endpoint (no OAuth). Recommended to run no more than twice
 * a day to stay polite with Reddit's anonymous rate limits.
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
 * Usage: php download.php
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

function http_get(string $url, string $userAgent): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => $userAgent,
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

$env = load_env($root.'/.env');

date_default_timezone_set($env['APP_TIMEZONE'] ?? 'UTC');

$userAgent = $env['REDDIT_USER_AGENT'] ?? 'SimpleSpyLogger-RedditScraper/1.0';
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

$capturedAt = date('Y-m-d H:i:s');
$host = 'reddit.com';
$totalInserted = 0;
$userIndex = 0;
$failures = [];

foreach ($usernames as $username) {
    if ($userIndex++ > 0) {
        // Be polite to Reddit's anonymous endpoint between users.
        sleep(1);
    }

    $url = 'https://www.reddit.com/user/'.urlencode($username)
        .'/comments.json?limit='.$perPage.'&sort=new&raw_json=1';

    $resp = http_get($url, $userAgent);
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

        try {
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
                ':content' => $c['body'] ?? null,
                ':referenced_external_id' => $referenced,
                ':sent_at' => isset($c['created_utc']) ? date('Y-m-d H:i:s', (int) $c['created_utc']) : null,
                ':source_edited_at' => $editedAt,
                ':deleted_at' => $isDeleted ? $capturedAt : null,
                ':captured_at' => $capturedAt,
                ':payload' => $payload,
                ':created_at' => $capturedAt,
                ':updated_at' => $capturedAt,
            ]);
            $insertedForUser += $insert->rowCount();
        } catch (PDOException $e) {
            fwrite(STDERR, "Skipped comment {$c['id']}: ".$e->getMessage()."\n");
        }
    }

    echo "Reddit: $username - $fetched comments fetched, $insertedForUser new.\n";
    $totalInserted += $insertedForUser;
}

echo "Reddit: inserted $totalInserted new comments total.\n";

if ($failures) {
    $total = count($usernames);
    $failed = count($failures);
    $subject = "SimpleSpyLogger reddit scraper: $failed/$total users failed to fetch";
    $lines = ["$failed of $total users failed to fetch on $capturedAt:", ''];
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
