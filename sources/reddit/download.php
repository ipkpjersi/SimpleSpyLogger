<?php
/**
 * SimpleSpyLogger - Reddit comment scraper
 *
 * Fetches every accessible comment for the configured Reddit user(s) via
 * the official OAuth API and inserts them into the SimpleSpyLogger
 * messages table via PDO.
 *
 * A Reddit comment is mapped as: subreddit -> container, post -> channel,
 * commenter -> author.
 *
 * Reddit listings cap at ~1000 most recent items per user; older comments
 * cannot be retrieved through this endpoint.
 *
 * Usage: php download.php
 */

$root = __DIR__;

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

function http_request(string $method, string $url, array $headers, ?string $body, string $userAgent): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
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

function get_oauth_token(string $clientId, string $clientSecret, string $userAgent): ?string
{
    $resp = http_request(
        'POST',
        'https://www.reddit.com/api/v1/access_token',
        [
            'Authorization: Basic '.base64_encode($clientId.':'.$clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        'grant_type=client_credentials',
        $userAgent
    );
    if (! $resp['ok']) {
        fwrite(STDERR, "OAuth token request failed (HTTP {$resp['code']}): {$resp['body']}\n");

        return null;
    }
    $data = json_decode($resp['body'], true);

    return is_array($data) && isset($data['access_token']) ? (string) $data['access_token'] : null;
}

$env = load_env($root.'/.env');

date_default_timezone_set($env['APP_TIMEZONE'] ?? 'UTC');

$clientId = $env['REDDIT_CLIENT_ID'] ?? '';
$clientSecret = $env['REDDIT_CLIENT_SECRET'] ?? '';
$userAgent = $env['REDDIT_USER_AGENT'] ?? 'SimpleSpyLogger-RedditScraper/1.0';
$usernames = array_filter(array_map('trim', explode(',', $env['REDDIT_TARGET_USERNAMES'] ?? '')));
$perPage = (int) ($env['REDDIT_PER_PAGE'] ?? 100);
if ($perPage < 1 || $perPage > 100) {
    $perPage = 100;
}

if ($clientId === '' || $clientSecret === '' || ! $usernames) {
    fwrite(STDERR, "REDDIT_CLIENT_ID, REDDIT_CLIENT_SECRET, and REDDIT_TARGET_USERNAMES must be set in .env\n");
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

$token = get_oauth_token($clientId, $clientSecret, $userAgent);
if ($token === null) {
    fwrite(STDERR, "Failed to obtain Reddit OAuth token.\n");
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

foreach ($usernames as $username) {
    $after = null;
    $fetched = 0;
    $insertedForUser = 0;

    while (true) {
        $url = 'https://oauth.reddit.com/user/'.urlencode($username)
            .'/comments?limit='.$perPage.'&sort=new&raw_json=1';
        if ($after !== null) {
            $url .= '&after='.urlencode($after);
        }

        $resp = http_request('GET', $url, ['Authorization: Bearer '.$token], null, $userAgent);
        if (! $resp['ok']) {
            fwrite(STDERR, "HTTP {$resp['code']} for $url\n");
            break;
        }

        $data = json_decode($resp['body'], true);
        $children = $data['data']['children'] ?? [];
        if (! is_array($children) || ! $children) {
            break;
        }

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

        $after = $data['data']['after'] ?? null;
        if (! is_string($after) || $after === '') {
            break;
        }

        // Polite pacing for Reddit's 100 QPM free-tier limit.
        usleep(500_000);
    }

    echo "Reddit: $username - $fetched comments fetched, $insertedForUser new.\n";
    $totalInserted += $insertedForUser;
}

echo "Reddit: inserted $totalInserted new comments total.\n";
