<?php
/**
 * SimpleSpyLogger - Lemmy comment scraper
 *
 * Fetches every comment for the configured Lemmy user(s) through the
 * instance's /api/v3 endpoint and inserts them into the SimpleSpyLogger
 * messages table via PDO. Adapted from the standalone lemmyuserscraper.php.
 *
 * A Lemmy comment is mapped as: community -> container, post -> channel,
 * commenter -> author.
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

function fetch_json(string $url, ?string &$error = null, ?string &$bodyExcerpt = null): ?array
{
    $error = null;
    $bodyExcerpt = null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'SimpleSpyLogger-LemmyScraper/1.0',
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = 'curl: '.curl_error($ch);
        fwrite(STDERR, $error."\n");
        curl_close($ch);

        return null;
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) {
        $error = "HTTP $code";
        $bodyExcerpt = mb_substr((string) $body, 0, 300);
        fwrite(STDERR, "HTTP $code for $url\n");

        return null;
    }
    $data = json_decode($body, true);
    if (! is_array($data)) {
        $error = 'JSON parse failure';
        $bodyExcerpt = mb_substr((string) $body, 0, 300);
        fwrite(STDERR, "$error for $url\n");

        return null;
    }

    return $data;
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
        'SimpleSpyLogger lemmy scraper: test alert',
        "This is a test alert sent on $stamp to verify email and Discord delivery."
    );
    foreach ($results as $channel => $ok) {
        echo str_pad($channel, 8).': '.($ok ? 'sent' : 'not sent (disabled or failed - see STDERR)')."\n";
    }
    exit(in_array(true, $results, true) ? 0 : 1);
}

$instance = rtrim($env['LEMMY_INSTANCE'] ?? '', '/');
$usernames = array_filter(array_map('trim', explode(',', $env['LEMMY_USERNAME'] ?? '')));
$perPage = (int) ($env['LEMMY_PER_PAGE'] ?? 50);
if ($perPage < 1 || $perPage > 50) {
    $perPage = 50;
}

if ($instance === '' || ! $usernames) {
    fwrite(STDERR, "LEMMY_INSTANCE and LEMMY_USERNAME must be set in .env\n");
    exit(1);
}

$host = parse_url($instance, PHP_URL_HOST) ?: $instance;

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

$capturedAt = date('Y-m-d H:i:s');
$totalInserted = 0;
$totalUpdated = 0;
$failures = [];

foreach ($usernames as $username) {
    $base = $instance.'/api/v3/user/?username='.urlencode($username).'&sort=New&limit='.$perPage;

    $firstUrl = $base.'&page=1';
    $err = null;
    $bodyExcerpt = null;
    $first = fetch_json($firstUrl, $err, $bodyExcerpt);
    if ($first === null || ! isset($first['person_view'])) {
        $reason = $err ?? 'no person_view in API response';
        fwrite(STDERR, "Skipping '$username': $reason\n");
        $failures[] = [
            'username' => $username,
            'url' => $firstUrl,
            'error' => $reason,
            'body_excerpt' => $bodyExcerpt ?? '',
        ];
        continue;
    }

    $commentCount = (int) ($first['person_view']['counts']['comment_count'] ?? 0);
    $pages = max(1, (int) ceil($commentCount / $perPage));

    $comments = $first['comments'] ?? [];
    for ($p = 2; $p <= $pages; $p++) {
        $page = fetch_json($base.'&page='.$p);
        if ($page === null || empty($page['comments'])) {
            break;
        }
        $comments = array_merge($comments, $page['comments']);
    }

    $insertedForUser = 0;
    $updatedForUser = 0;
    foreach ($comments as $cv) {
        $comment = $cv['comment'] ?? null;
        $creator = $cv['creator'] ?? null;
        if (! $comment || ! $creator || empty($comment['id'])) {
            continue;
        }
        $community = $cv['community'] ?? [];
        $post = $cv['post'] ?? [];

        $externalId = $host.':c'.$comment['id'];

        // Parent comment id from the path "0.<id>.<id>..." - second-to-last segment.
        $referenced = null;
        if (! empty($comment['path'])) {
            $segments = explode('.', (string) $comment['path']);
            if (count($segments) >= 3) {
                $parent = $segments[count($segments) - 2];
                if ($parent !== '0') {
                    $referenced = $host.':c'.$parent;
                }
            }
        }

        $payload = json_encode([
            'ap_id' => $comment['ap_id'] ?? null,
            'path' => $comment['path'] ?? null,
            'post' => ['id' => $post['id'] ?? null, 'name' => $post['name'] ?? null],
            'community' => [
                'id' => $community['id'] ?? null,
                'name' => $community['name'] ?? null,
                'title' => $community['title'] ?? null,
            ],
            'counts' => $cv['counts'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $deleted = (! empty($comment['deleted']) || ! empty($comment['removed'])) ? $capturedAt : null;
        $newContent = $comment['content'] ?? null;
        $editedAt = ! empty($comment['updated']) ? date('Y-m-d H:i:s', strtotime($comment['updated'])) : null;

        try {
            $lookup->execute([':source' => 'lemmy', ':external_id' => $externalId]);
            $existing = $lookup->fetch(PDO::FETCH_ASSOC);

            if ($existing === false) {
                $insert->execute([
                    ':source' => 'lemmy',
                    ':external_id' => $externalId,
                    ':container_external_id' => isset($community['id']) ? (string) $community['id'] : null,
                    ':container_name' => $community['title'] ?? ($community['name'] ?? null),
                    ':channel_external_id' => isset($post['id']) ? (string) $post['id'] : null,
                    ':channel_name' => $post['name'] ?? null,
                    ':visibility' => 'public',
                    ':author_external_id' => (string) $creator['id'],
                    ':author_username' => (string) $creator['name'],
                    ':author_display_name' => $creator['display_name'] ?? null,
                    ':author_bot' => ! empty($creator['bot_account']) ? 1 : 0,
                    ':content' => $newContent,
                    ':referenced_external_id' => $referenced,
                    ':sent_at' => ! empty($comment['published']) ? date('Y-m-d H:i:s', strtotime($comment['published'])) : null,
                    ':source_edited_at' => $editedAt,
                    ':deleted_at' => $deleted,
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
                        ':deleted_at' => $deleted,
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
            fwrite(STDERR, "Skipped comment {$comment['id']}: ".$e->getMessage()."\n");
        }
    }

    echo "Lemmy: $username - $commentCount comments reported, ".count($comments)." fetched, $insertedForUser new, $updatedForUser edited.\n";
    $totalInserted += $insertedForUser;
    $totalUpdated += $updatedForUser;
}

$summary = "Lemmy: inserted $totalInserted new comments, $totalUpdated edited total.";
echo $summary."\n";
log_import_summary($summary);

if ($failures) {
    $total = count($usernames);
    $failed = count($failures);
    $subject = "SimpleSpyLogger lemmy scraper: $failed/$total users failed to fetch";
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
