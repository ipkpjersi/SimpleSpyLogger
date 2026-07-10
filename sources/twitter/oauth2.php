<?php
/**
 * Shared X/Twitter OAuth 2.0 helpers for the twitter source.
 *
 * The scraper reads on behalf of each account using OAuth 2.0 Authorization Code
 * with PKCE (read-only scopes: tweet.read, users.read, dm.read, offline.access).
 * oauth2_authorize.php performs the one-time browser authorization to mint the
 * first refresh token; download.php then exchanges the refresh token for a
 * short-lived access token on every run.
 *
 * These helpers deliberately avoid defining load_env()/env_bool() so this file
 * can be included alongside scripts that already define those.
 */

/**
 * Generate a PKCE (verifier, challenge) pair. The challenge is the base64url
 * SHA-256 of the verifier (code_challenge_method=S256).
 */
function twitter_pkce_pair(): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [$verifier, $challenge];
}

/**
 * POST to the token endpoint with HTTP Basic client authentication (X issues a
 * client secret, i.e. a confidential client). Returns
 * ['ok','code','error','data','raw'] where data is the decoded JSON or null.
 */
function twitter_oauth2_token(array $post, string $clientId, string $clientSecret): array
{
    $ch = curl_init('https://api.twitter.com/2/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic '.base64_encode($clientId.':'.$clientSecret),
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'SimpleSpyLogger-TwitterScraper/1.0',
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);

        return ['ok' => false, 'code' => 0, 'error' => $err, 'data' => null, 'raw' => ''];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string) $resp, true);

    return [
        'ok' => $code < 400,
        'code' => $code,
        'error' => null,
        'data' => is_array($data) ? $data : null,
        'raw' => (string) $resp,
    ];
}

/**
 * Exchange a refresh token for a fresh access token. X rotates refresh tokens,
 * so the response's refresh_token replaces the one passed in and must be
 * persisted by the caller.
 */
function twitter_oauth2_refresh(string $refreshToken, string $clientId, string $clientSecret): array
{
    return twitter_oauth2_token([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => $clientId,
    ], $clientId, $clientSecret);
}

/**
 * Set (or append) a single KEY=value line in a .env-style file, preserving the
 * rest of the file. Written via a temp file + rename so an interrupted write
 * never leaves the credentials file half-written.
 */
function twitter_env_set(string $path, string $key, string $value): bool
{
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    if ($lines === false) {
        $lines = [];
    }
    $out = [];
    $found = false;
    foreach ($lines as $line) {
        if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $line)) {
            $out[] = $key.'='.$value;
            $found = true;
        } else {
            $out[] = $line;
        }
    }
    if (! $found) {
        $out[] = $key.'='.$value;
    }
    // Write to a temp file, flush it to disk, then atomically rename into place.
    // The fsync forces the rotated token's bytes onto disk before the rename, so
    // a crash or power loss between the write and the rename cannot leave the new
    // token only in the OS page cache (and thus lost, stranding the account on a
    // now-invalid token). fsync() is PHP 8.1+; degrade gracefully where it is not
    // available. The atomic rename means a reader never sees a half-written file
    // regardless. This does NOT protect against two processes writing at once -
    // that is what the twitter_lock() run lock is for.
    $tmp = $path.'.tmp';
    $fh = fopen($tmp, 'wb');
    if ($fh === false) {
        return false;
    }
    if (fwrite($fh, implode("\n", $out)."\n") === false || ! fflush($fh)) {
        fclose($fh);

        return false;
    }
    if (function_exists('fsync')) {
        fsync($fh);
    }
    fclose($fh);

    return rename($tmp, $path);
}

/**
 * A stable fingerprint of a secret token for logs. Emits a short hash prefix,
 * the token length, and the last 7 characters. Identical tokens produce
 * identical fingerprints, so comparing fingerprints across runs shows whether
 * .env actually rotated (fingerprint changed) or a stale token is being reused
 * (unchanged), and comparing two runs' logs reveals whether they used the same
 * token - i.e. whether they overlapped on it. The plaintext tail lets a logged
 * token be grepped directly against .env; 7 of a ~91-char high-entropy token is
 * a negligible fraction, and the tail is suppressed for short tokens so a large
 * share of one is never exposed. Logs here are local/gitignored (cron.log).
 */
function twitter_token_fingerprint(string $token): string
{
    if ($token === '') {
        return 'empty';
    }
    $tail = strlen($token) > 14 ? ' tail:'.substr($token, -7) : '';

    return 'sha1:'.substr(sha1($token), 0, 10).' len:'.strlen($token).$tail;
}

/**
 * Acquire a process-wide advisory lock so no two token-refreshing twitter
 * scripts (download.php, resolve_users.php) run at the same time. Concurrent
 * runs race on X's single-use OAuth 2.0 refresh token and on the .env
 * read-modify-write, which can strand a rotated token and permanently
 * invalidate an account. Records the holder's pid and start time in the lock
 * file for diagnosis. The lock is released automatically when the returned
 * handle is closed or the process exits (including on fatal error or kill), so a
 * crash never leaves it stuck. Returns the lock handle (keep it in scope for the
 * whole run) on success, or false if another run already holds it or the lock
 * file cannot be opened.
 */
function twitter_lock(string $path)
{
    $fh = fopen($path, 'c');
    if ($fh === false) {
        return false;
    }
    if (! flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);

        return false;
    }
    // Record who holds it (best effort; a failure here does not release the lock).
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, 'pid='.getmypid().' since='.date('Y-m-d H:i:s O')."\n");
    fflush($fh);

    return $fh;
}

/**
 * Best-effort read of the pid that twitter_lock() recorded in a lock file, so a
 * lock_contended audit event can name the run that HELD the lock (the audit
 * line's own pid= is the run that was refused). The holder still owns the lock
 * when our non-blocking acquire fails, so its pid is reliably present; returns
 * 'unknown' only if the file is missing or unparseable.
 */
function twitter_lock_holder_pid(string $path): string
{
    if (! is_file($path)) {
        return 'unknown';
    }
    if (preg_match('/pid=(\d+)/', (string) file_get_contents($path), $m)) {
        return $m[1];
    }

    return 'unknown';
}

/**
 * Append one line to the token-change audit log. EVERY script that refreshes or
 * mints a refresh token (download.php, resolve_users.php, oauth2_authorize.php)
 * calls this, so there is a single durable, append-only record of every token
 * change regardless of how the script was invoked - cron, manual, or interactive
 * - and regardless of where STDOUT/STDERR were pointed. The per-run cron.log only
 * captures cron download.php runs; this file captures all of them, so it is the
 * log to read first when a token goes invalid: it shows who rotated it, when,
 * from which token to which, and whether the rotation persisted. A stale token
 * with NO preceding rotation line here points at an external revoke rather than
 * a local mishandling. The write is not suppressed: if it fails, PHP's warning
 * (itself informative) surfaces on STDERR; it never halts the run. LOCK_EX keeps
 * concurrent appends from interleaving.
 */
function twitter_audit(string $path, string $event, array $fields = []): void
{
    $parts = [date('Y-m-d H:i:s O'), 'pid='.getmypid(), 'event='.$event];
    foreach ($fields as $k => $v) {
        $parts[] = $k.'='.$v;
    }

    file_put_contents($path, implode(' ', $parts)."\n", FILE_APPEND | LOCK_EX);
}
