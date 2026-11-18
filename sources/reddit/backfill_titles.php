<?php
/**
 * SimpleSpyLogger - fill in Reddit post titles and subreddit ids that the data
 * export does not contain.
 *
 * import_export.php stores each exported comment with its post id but, unless
 * the title was already known, no post title (channel_name) and no subreddit id
 * (container_external_id), because the export has neither. This looks them up
 * on the old-Reddit by_id page (old.reddit.com/by_id/t3_a,t3_b,...), 100 posts
 * per request, using the same dedicated browser profile as download.php
 * (REDDIT_FETCHER=browser), and fills them in on every row of that post or
 * subreddit. Only those two columns are written; content, payload and
 * updated_at are not touched.
 *
 * The JSON lookups (/api/info.json, /by_id/....json) answer "blocked by network
 * security" to this profile even while comments.json works, so the HTML page is
 * used; each post's title, subreddit and subreddit id are on its listing entry.
 *
 * Only posts still missing a title (plus one post per subreddit still missing
 * its id) are requested, so it is safe to stop and re-run at any time.
 *
 * by_id leaves out posts their author deleted, and posts in banned or private
 * subreddits. per-post mode fetches each remaining post on its own
 * (/comments/<id>.json?limit=1), which still returns a deleted post's title,
 * or "[deleted by user]" for older deletions; that is stored as is, so the post
 * is not fetched again. It is one request per post, so run the batch mode
 * first. When a subreddit answers 403/404 the rest of its posts are skipped for
 * that run.
 *
 * Usage:
 *   php backfill_titles.php             fetch everything still missing (by_id)
 *   php backfill_titles.php per-post    fetch the leftovers one post at a time
 *   php backfill_titles.php dry-run     fetch and report, write nothing
 *   php backfill_titles.php no-delay    skip the pause between requests
 *   php backfill_titles.php limit=5     stop after 5 requests
 */

declare(strict_types=1);

$root = __DIR__;

require_once __DIR__.'/../notifier.php';
require_once __DIR__.'/../browser/browser_fetch.php';

/** Ids per by_id request; a listing page holds at most 100. */
const IDS_PER_REQUEST = 100;

/** Give up after this many failed requests in a row. */
const MAX_CONSECUTIVE_FAILURES = 3;

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

/**
 * Posts out of an old-Reddit by_id listing page: t3_ id -> title, subreddit,
 * t5_ id. Each post is a div.thing carrying data-fullname, data-subreddit and
 * data-subreddit-fullname, with the title in p.title > a.title. Returns null
 * when the page has no listing at all (login wall, block page).
 *
 * @return array<string, array{title: string, subreddit: string, subreddit_id: string}>|null
 */
function parse_by_id_page(string $html): ?array
{
    $doc = new DOMDocument();
    $prevErrors = libxml_use_internal_errors(true);
    // The XML prolog makes libxml read the page as UTF-8 instead of Latin-1.
    $loaded = $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prevErrors);
    if (! $loaded) {
        return null;
    }
    $xpath = new DOMXPath($doc);
    if ($xpath->query('//*[@id="siteTable"]')->length === 0) {
        return null;
    }

    $posts = [];
    foreach ($xpath->query('//div[@data-type="link" and starts-with(@data-fullname, "t3_")]') as $thing) {
        if (! $thing instanceof DOMElement) {
            continue;
        }
        $titleNode = $xpath->query('.//p[contains(concat(" ", normalize-space(@class), " "), " title ")]'
            .'/a[contains(concat(" ", normalize-space(@class), " "), " title ")]', $thing)->item(0);
        $posts[$thing->getAttribute('data-fullname')] = [
            'title' => $titleNode !== null ? trim($titleNode->textContent) : '',
            'subreddit' => $thing->getAttribute('data-subreddit'),
            'subreddit_id' => $thing->getAttribute('data-subreddit-fullname'),
        ];
    }

    return $posts;
}

/**
 * The post out of a /comments/<id>.json response, in the same shape as
 * parse_by_id_page(). Returns [] when Reddit answers with a JSON error (banned,
 * private or missing post), and null when the body is not JSON at all (login
 * wall, "blocked by network security" page).
 *
 * @return array<string, array{title: string, subreddit: string, subreddit_id: string}>|null
 */
function parse_post_json(string $body): ?array
{
    $data = json_decode($body, true);
    if (! is_array($data)) {
        return null;
    }
    if (isset($data['error'])) {
        return [];
    }
    $p = $data[0]['data']['children'][0]['data'] ?? null;
    if (! is_array($p) || empty($p['name'])) {
        return [];
    }

    return [(string) $p['name'] => [
        'title' => (string) ($p['title'] ?? ''),
        'subreddit' => (string) ($p['subreddit'] ?? ''),
        'subreddit_id' => (string) ($p['subreddit_id'] ?? ''),
    ]];
}

$env = load_env($root.'/.env');
date_default_timezone_set($env['APP_TIMEZONE'] ?? 'UTC');

$cliArgs = array_slice($argv, 1);
$dryRun = (bool) array_intersect($cliArgs, ['dry-run', '--dry-run']);
$skipDelay = (bool) array_intersect($cliArgs, ['no-delay', '--no-delay', 'now']);
$perPost = (bool) array_intersect($cliArgs, ['per-post', '--per-post']);
$limit = 0;
foreach ($cliArgs as $arg) {
    if (preg_match('/^(?:--)?limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

if (strtolower(trim($env['REDDIT_FETCHER'] ?? 'http')) !== 'browser') {
    fwrite(STDERR, "backfill_titles.php only supports REDDIT_FETCHER=browser (the dedicated profile download.php uses).\n");
    exit(1);
}
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

// Posts with no title, newest first so recent threads fill in first, then one
// post from each subreddit that still has no id (its title may already be known).
// Each wanted post maps to its subreddit name, which per-post mode uses to skip
// subreddits that turned out to be unreachable.
/** @var array<string, string> $wanted */
$wanted = [];
foreach ($pdo->query(
    "SELECT channel_external_id, MIN(container_name) AS sub FROM messages
      WHERE source = 'reddit' AND channel_external_id LIKE 't3\\_%' AND channel_name IS NULL
      GROUP BY channel_external_id
      ORDER BY MAX(sent_at) DESC"
) as $r) {
    $wanted[(string) $r['channel_external_id']] = strtolower((string) $r['sub']);
}
$missingTitles = count($wanted);
foreach ($pdo->query(
    "SELECT MIN(channel_external_id) AS post FROM messages
      WHERE source = 'reddit' AND container_external_id IS NULL AND container_name IS NOT NULL
        AND channel_external_id LIKE 't3\\_%'
      GROUP BY container_name"
) as $r) {
    $wanted[(string) $r['post']] ??= '';
}
$ids = array_keys($wanted);
$batches = array_chunk($ids, $perPost ? 1 : IDS_PER_REQUEST);
$prefix = ($dryRun ? 'Reddit titles (dry run)' : 'Reddit titles').($perPost ? ' per-post' : '');
echo "$prefix: $missingTitles posts need a title; ".count($ids).' ids to look up in '.count($batches)." requests.\n";
if (! $batches) {
    exit(0);
}

$setTitle = $pdo->prepare(
    "UPDATE messages SET channel_name = :title
      WHERE source = 'reddit' AND channel_external_id = :post AND channel_name IS NULL"
);
$setSubreddit = $pdo->prepare(
    "UPDATE messages SET container_external_id = :sub_id
      WHERE source = 'reddit' AND container_external_id IS NULL AND container_name = :sub"
);

$requests = 0;
$failuresInRow = 0;
$titled = 0;
$rowsTitled = 0;
$rowsSubreddit = 0;
$notReturned = 0;
/** @var array<string, true> $subredditsDone */
$subredditsDone = [];
/** @var array<string, true> $unavailableSubs per-post mode: subreddits that answered 403/404 */
$unavailableSubs = [];
$skipped = 0;
$lastError = '';

foreach ($batches as $i => $batch) {
    if ($limit > 0 && $requests >= $limit) {
        echo "$prefix: stopping after $requests requests (limit=$limit).\n";
        break;
    }
    if ($perPost && isset($unavailableSubs[$wanted[$batch[0]]])) {
        $skipped++;
        continue;
    }
    if ($requests > 0 && ! $skipDelay) {
        // Polite random pause between requests.
        sleep(random_int(3, 8));
    }

    if ($perPost) {
        $url = 'https://www.reddit.com/comments/'.substr($batch[0], 3).'.json?limit=1&raw_json=1';
        $resp = browser_fetch($url, $browserOpts);
        // A JSON error body (403 banned/private, 404 gone) is an answer, not a
        // fetch failure, so parse it even when the status is not ok.
        // A 429 is also JSON, but it says nothing about the subreddit.
        $posts = $resp['body'] !== null && $resp['code'] !== 429 ? parse_post_json((string) $resp['body']) : null;
        if ($posts === [] && $wanted[$batch[0]] !== '') {
            $unavailableSubs[$wanted[$batch[0]]] = true;
        }
    } else {
        $url = 'https://old.reddit.com/by_id/'.implode(',', $batch).'?limit='.IDS_PER_REQUEST;
        $resp = browser_fetch($url, $browserOpts);
        $posts = $resp['ok'] ? parse_by_id_page((string) $resp['body']) : null;
    }
    $requests++;
    if ($posts === null) {
        $lastError = $resp['ok'] ? 'page has no listing' : ($resp['error'] ?? 'HTTP '.$resp['code']);
        fwrite(STDERR, "$prefix: request ".($i + 1).' failed: '.$lastError."\n");
        if ($resp['code'] === 429) {
            // Rate limited: back off, then carry on with the next batch; the
            // skipped ids are picked up on a re-run.
            sleep(60);
        }
        if (++$failuresInRow >= MAX_CONSECUTIVE_FAILURES) {
            fwrite(STDERR, "$prefix: giving up after ".MAX_CONSECUTIVE_FAILURES." failed requests in a row.\n");
            break;
        }
        continue;
    }
    $failuresInRow = 0;
    $notReturned += count($batch) - count(array_intersect_key($posts, array_flip($batch)));

    if (! $dryRun) {
        $pdo->beginTransaction();
    }
    foreach ($posts as $postId => $p) {
        if ($p['title'] !== '') {
            $titled++;
            if (! $dryRun) {
                $setTitle->execute([':title' => mb_substr($p['title'], 0, 255), ':post' => $postId]);
                $rowsTitled += $setTitle->rowCount();
            }
        }
        if ($p['subreddit'] !== '' && $p['subreddit_id'] !== '' && ! isset($subredditsDone[strtolower($p['subreddit'])])) {
            $subredditsDone[strtolower($p['subreddit'])] = true;
            if (! $dryRun) {
                $setSubreddit->execute([':sub_id' => $p['subreddit_id'], ':sub' => $p['subreddit']]);
                $rowsSubreddit += $setSubreddit->rowCount();
            }
        }
    }
    if (! $dryRun) {
        $pdo->commit();
    }

    if ($requests % ($perPost ? 250 : 25) === 0) {
        echo "$prefix: ".($i + 1).'/'.count($batches)." done ($requests requests), $titled titles found so far.\n";
    }
}

$summary = "$prefix: $requests requests, $titled post titles found ($rowsTitled rows), "
    .count($subredditsDone)." subreddit ids ($rowsSubreddit rows), $notReturned ids not returned by Reddit"
    .($perPost ? ', '.count($unavailableSubs)." unreachable subreddits ($skipped posts skipped)" : '').'.';
echo $summary."\n";
if (! $dryRun) {
    log_import_summary($summary);
}
if ($failuresInRow >= MAX_CONSECUTIVE_FAILURES) {
    if (! $dryRun) {
        send_alert(
            $env,
            'SimpleSpyLogger reddit title backfill stopped',
            "backfill_titles.php gave up after ".MAX_CONSECUTIVE_FAILURES." failed requests in a row.\nLast error: $lastError\n"
            .'If this is HTTP 403, log the dedicated profile back in: python3 sources/browser/browser_fetch.py --login https://www.reddit.com/login'
        );
    }
    exit(1);
}
