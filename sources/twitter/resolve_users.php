<?php
/**
 * SimpleSpyLogger - Twitter/X user resolver (id -> username cache).
 *
 * The archive and DM API give us DM partners only as numeric ids. This resolves
 * those ids to @usernames via GET /2/users?ids=... and caches them in the
 * external_users table, so we only ever pay to resolve each partner once
 * (User: Read is $0.010 per user; batched 100 per request; deduped by X within a
 * UTC day). It then backfills DM channel_name with the partner's @handle so the
 * UI names who each conversation is with instead of showing a bare id.
 *
 * Re-running only resolves partners not already cached and re-applies handles to
 * any DM rows still showing an id, so it is safe and cheap to run again after a
 * new import.
 *
 * Usage:
 *   php resolve_users.php            resolve uncached partners, backfill channel_name
 *   php resolve_users.php dry-run    show how many would be resolved + est. cost, no API calls
 */

$root = __DIR__;
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

$envPath = $root.'/.env';
$env = load_env($envPath);
date_default_timezone_set($env['APP_TIMEZONE'] ?? 'UTC');

$dryRun = in_array($argv[1] ?? '', ['dry-run', '--dry-run'], true);
$costPerUser = (float) ($env['TWITTER_COST_PER_USER_READ'] ?? 0.010);

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

// Distinct 1:1 DM partners across all twitter DM rows (partner = the conversation
// participant that is not the owner), minus any we have already cached.
$partners = [];
$rows = $pdo->query(
    "SELECT DISTINCT channel_external_id, container_external_id
       FROM messages
      WHERE source = 'twitter' AND visibility = 'private'
        AND channel_external_id LIKE '%-%'"
);
foreach ($rows as $r) {
    $pid = dm_partner_id((string) $r['channel_external_id'], (string) $r['container_external_id']);
    if ($pid !== null) {
        $partners[$pid] = true;
    }
}

$known = [];
foreach ($pdo->query("SELECT external_id FROM external_users WHERE source = 'twitter'") as $r) {
    $known[(string) $r['external_id']] = true;
}
$toResolve = array_values(array_diff(array_keys($partners), array_keys($known)));

printf(
    "%d distinct DM partners, %d already cached, %d to resolve (est. cost \$%.2f).\n",
    count($partners), count($known), count($toResolve), count($toResolve) * $costPerUser
);

if ($dryRun) {
    exit(0);
}

if ($toResolve) {
    // Acquire an access token from the first configured account (any user-context
    // token can look up public users). Persist the rotated refresh token.
    $accessToken = null;
    for ($i = 1; $i <= 50 && $accessToken === null; $i++) {
        $p = "TWITTER_A{$i}_";
        if (empty($env[$p.'CLIENT_ID']) || empty($env[$p.'CLIENT_SECRET']) || empty($env[$p.'REFRESH_TOKEN'])) {
            continue;
        }
        $refresh = twitter_oauth2_refresh($env[$p.'REFRESH_TOKEN'], $env[$p.'CLIENT_ID'], $env[$p.'CLIENT_SECRET']);
        if (! $refresh['ok'] || empty($refresh['data']['access_token'])) {
            fwrite(STDERR, "Token refresh failed for A{$i} (HTTP {$refresh['code']}); trying next account.\n");
            continue;
        }
        $accessToken = (string) $refresh['data']['access_token'];
        if (! empty($refresh['data']['refresh_token'])) {
            twitter_env_set($envPath, $p.'REFRESH_TOKEN', (string) $refresh['data']['refresh_token']);
        }
    }
    if ($accessToken === null) {
        fwrite(STDERR, "Could not obtain an access token from any configured account.\n");
        exit(1);
    }

    $insertUser = $pdo->prepare(
        "INSERT INTO external_users (source, external_id, username, display_name, resolved_at, created_at, updated_at)
         VALUES ('twitter', :eid, :un, :dn, :ra, :ca, :ua)
         ON DUPLICATE KEY UPDATE username = VALUES(username), display_name = VALUES(display_name),
                                 resolved_at = VALUES(resolved_at), updated_at = VALUES(updated_at)"
    );

    $now = date('Y-m-d H:i:s');
    $resolvedOk = 0;
    $resolvedNull = 0;

    foreach (array_chunk($toResolve, 100) as $chunk) {
        $url = 'https://api.twitter.com/2/users?'.http_build_query([
            'ids' => implode(',', $chunk),
            'user.fields' => 'username,name',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$accessToken],
            CURLOPT_USERAGENT => 'SimpleSpyLogger-TwitterResolver/1.0',
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            fwrite(STDERR, 'curl error: '.curl_error($ch)."\n");
            curl_close($ch);
            continue;
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            fwrite(STDERR, "HTTP $code from users lookup: ".mb_substr((string) $body, 0, 300)."\n");
            continue;
        }

        $data = json_decode((string) $body, true);
        $found = [];
        foreach ($data['data'] ?? [] as $u) {
            if (empty($u['id'])) {
                continue;
            }
            $found[(string) $u['id']] = true;
            $insertUser->execute([
                ':eid' => (string) $u['id'],
                ':un' => $u['username'] ?? null,
                ':dn' => $u['name'] ?? null,
                ':ra' => $now,
                ':ca' => $now,
                ':ua' => $now,
            ]);
            $resolvedOk++;
        }
        // Ids the API could not return (suspended/deleted/protected) are cached
        // with a null username and a resolved_at stamp so we do not pay to retry
        // them on every run.
        foreach ($chunk as $id) {
            if (! isset($found[(string) $id])) {
                $insertUser->execute([
                    ':eid' => (string) $id,
                    ':un' => null,
                    ':dn' => null,
                    ':ra' => $now,
                    ':ca' => $now,
                    ':ua' => $now,
                ]);
                $resolvedNull++;
            }
        }
    }

    printf("Resolved %d usernames, %d unresolvable (cached as null), est. cost \$%.2f.\n",
        $resolvedOk, $resolvedNull, $resolvedOk * $costPerUser);
}

// Backfill DM channel_name with the partner's @handle wherever we now have one.
$map = load_twitter_username_map($pdo);
$now = date('Y-m-d H:i:s');
$upd = $pdo->prepare('UPDATE messages SET channel_name = :cn, updated_at = :ua WHERE id = :id');
$updated = 0;
$dmRows = $pdo->query(
    "SELECT id, channel_external_id, container_external_id, channel_name
       FROM messages
      WHERE source = 'twitter' AND visibility = 'private' AND channel_external_id LIKE '%-%'"
);
foreach ($dmRows as $r) {
    $pid = dm_partner_id((string) $r['channel_external_id'], (string) $r['container_external_id']);
    if ($pid === null) {
        continue;
    }
    // @handle when resolved, otherwise the partner's id (still better than a
    // bare "DM": it distinguishes conversations with deleted/suspended accounts).
    $newName = dm_channel_name($pid, false, $map);
    if ((string) $r['channel_name'] === $newName) {
        continue;
    }
    $upd->execute([':cn' => $newName, ':ua' => $now, ':id' => $r['id']]);
    $updated++;
}

echo "Backfilled channel_name on $updated DM row(s).\n";
