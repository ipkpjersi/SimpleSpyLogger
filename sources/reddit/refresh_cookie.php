<?php

declare(strict_types=1);

/**
 * SimpleSpyLogger - Reddit session cookie auto-refresh.
 *
 * Reddit rotates the reddit_session cookie on every browser login and revokes
 * the previous one server-side, so a cookie stored in .env goes stale (and then
 * 403s) the next time you log into Reddit in Chrome -- even though the cookie's
 * own JWT exp is still months away. This reads the live reddit_session straight
 * out of Chrome's cookie store, decrypts it, and writes it back into .env so the
 * scraper always sends the current session.
 *
 * Decryption (Linux, Chrome "v11" cookies):
 *   1. AES key = PBKDF2-HMAC-SHA1(password, "saltysalt", 1 iteration, 16 bytes).
 *   2. The password is the "Chrome Safe Storage" secret in the login keyring.
 *      This box has no secret-tool, so it is read via python3 + secretstorage.
 *   3. Value = AES-128-CBC decrypt of the bytes after the 3-byte "v11" tag,
 *      IV = 16 spaces, then strip PKCS7 padding and the leading 32-byte
 *      sha256(host) tamper-check prefix that Chrome prepends.
 *
 * Best-effort by design: any failure (Chrome closed mid-write, keyring locked
 * because you are logged out, profile or cookie missing) returns null and leaves
 * .env untouched, so the scraper simply falls back to the stored cookie.
 *
 * Config (sources/reddit/.env):
 *   REDDIT_COOKIE_AUTOREFRESH    set to "false" to disable (default: enabled)
 *   REDDIT_CHROME_PROFILE        Chrome profile directory name (default "Default")
 *   REDDIT_CHROME_COOKIES_PATH   full path override to a Chrome Cookies sqlite file
 */

/**
 * Refresh REDDIT_SESSION_COOKIE in .env from the live Chrome cookie store.
 *
 * Returns the fresh cookie on success (and rewrites .env when it changed), or
 * null when refresh is disabled or the live cookie could not be read.
 */
function refreshRedditSessionCookie(string $envPath, array $env): ?string
{
    if (strtolower(trim($env['REDDIT_COOKIE_AUTOREFRESH'] ?? 'true')) === 'false') {
        return null;
    }

    $cookie = redditDecryptLiveSessionCookie($env);
    if ($cookie === null) {
        return null;
    }

    // Only rewrite .env when the value actually changed.
    if (trim($env['REDDIT_SESSION_COOKIE'] ?? '') !== $cookie) {
        if (writeEnvValue($envPath, 'REDDIT_SESSION_COOKIE', $cookie)) {
            fwrite(STDERR, "[reddit] refreshed reddit_session cookie from Chrome\n");
        } else {
            fwrite(STDERR, "[reddit] decrypted a fresh cookie but failed to write it to .env\n");
        }
    }

    return $cookie;
}

/**
 * Decrypt the live reddit_session cookie out of Chrome's cookie store.
 * Returns the cookie value (a JWT) or null on any failure.
 */
function redditDecryptLiveSessionCookie(array $env): ?string
{
    if (!extension_loaded('sqlite3') || !extension_loaded('openssl')) {
        return null;
    }

    $path = trim($env['REDDIT_CHROME_COOKIES_PATH'] ?? '');
    if ($path === '') {
        $home = getenv('HOME') ?: '';
        $profile = trim($env['REDDIT_CHROME_PROFILE'] ?? '') ?: 'Default';
        $path = $home . '/.config/google-chrome/' . $profile . '/Cookies';
    }
    if (!is_file($path)) {
        fwrite(STDERR, "[reddit] cookie auto-refresh: Chrome cookie DB not found at $path\n");
        return null;
    }

    $key = redditChromeSafeStorageKey();
    if ($key === null) {
        fwrite(STDERR, "[reddit] cookie auto-refresh: could not read Chrome keyring key (logged out or keyring locked?)\n");
        return null;
    }

    // Chrome keeps the DB open, so copy it before reading rather than locking it.
    $tmp = tempnam(sys_get_temp_dir(), 'ssl_rc_');
    if ($tmp === false || !copy($path, $tmp)) {
        if (is_string($tmp) && is_file($tmp)) {
            unlink($tmp);
        }
        return null;
    }

    $cookieHex = null;
    try {
        $db = new SQLite3($tmp, SQLITE3_OPEN_READONLY);
        $stmt = $db->prepare(
            "SELECT hex(encrypted_value) FROM cookies " .
            "WHERE name = 'reddit_session' AND host_key LIKE '%reddit.com' " .
            "ORDER BY length(encrypted_value) DESC LIMIT 1"
        );
        $row = $stmt ? $stmt->execute()->fetchArray(SQLITE3_NUM) : false;
        $cookieHex = $row ? $row[0] : null;
        $db->close();
    } catch (\Throwable $e) {
        $cookieHex = null;
    }
    unlink($tmp);

    if (!is_string($cookieHex) || $cookieHex === '') {
        fwrite(STDERR, "[reddit] cookie auto-refresh: no reddit_session cookie in that Chrome profile\n");
        return null;
    }

    $enc = hex2bin($cookieHex);
    if ($enc === false || strlen($enc) < 4) {
        return null;
    }
    $prefix = substr($enc, 0, 3);
    if ($prefix !== 'v10' && $prefix !== 'v11') {
        return null;
    }

    $plain = openssl_decrypt(
        substr($enc, 3),
        'aes-128-cbc',
        $key,
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        str_repeat(' ', 16)
    );
    if (!is_string($plain) || $plain === '') {
        return null;
    }

    // Strip PKCS7 padding.
    $pad = ord(substr($plain, -1));
    if ($pad < 1 || $pad > 16 || $pad > strlen($plain)) {
        return null;
    }
    $plain = substr($plain, 0, -$pad);

    // Chrome (v11) prepends a 32-byte sha256(host) tamper check before the value.
    if (strncmp($plain, 'eyJ', 3) !== 0 && strlen($plain) > 32) {
        $plain = substr($plain, 32);
    }
    if (strncmp($plain, 'eyJ', 3) !== 0) {
        fwrite(STDERR, "[reddit] cookie auto-refresh: decrypted value is not a JWT, skipping\n");
        return null;
    }

    return $plain;
}

/**
 * Derive Chrome's AES key from the "Chrome Safe Storage" keyring secret.
 * Returns the 16-byte key, or null if the keyring secret is unavailable.
 */
function redditChromeSafeStorageKey(): ?string
{
    // secret-tool is not installed on this box, so read the Secret Service
    // (gnome-keyring) directly via python3 + secretstorage over D-Bus.
    $py = <<<'PY'
import sys
try:
    import secretstorage
    conn = secretstorage.dbus_init()
    for coll in secretstorage.get_all_collections(conn):
        for item in coll.get_all_items():
            if item.get_label() == 'Chrome Safe Storage':
                sys.stdout.buffer.write(item.get_secret())
                sys.exit(0)
    sys.exit(3)
except Exception:
    sys.exit(4)
PY;

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['python3', '-c', $py], $descriptors, $pipes);
    if (!is_resource($proc)) {
        return null;
    }
    $password = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if ($code !== 0 || !is_string($password) || $password === '') {
        return null;
    }

    return hash_pbkdf2('sha1', $password, 'saltysalt', 1, 16, true);
}

/**
 * Rewrite a single KEY=value line in a .env file, preserving everything else.
 * Appends the key if it is not already present. Returns true on success.
 */
function writeEnvValue(string $path, string $key, string $value): bool
{
    if (!is_file($path)) {
        return false;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return false;
    }

    $out = [];
    $replaced = false;
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if (!$replaced && $trim !== '' && $trim[0] !== '#' && str_starts_with($trim, $key . '=')) {
            $out[] = $key . '=' . $value;
            $replaced = true;
        } else {
            $out[] = $line;
        }
    }
    if (!$replaced) {
        $out[] = $key . '=' . $value;
    }

    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, implode("\n", $out) . "\n", LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $path);
}

// When run directly (php refresh_cookie.php) perform a one-off refresh and
// report what happened. Skipped when this file is require'd by download.php,
// since then $argv[0] points at download.php rather than this file.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $envPath = __DIR__ . '/.env';
    if (!is_file($envPath)) {
        fwrite(STDERR, "refresh_cookie: $envPath not found\n");
        exit(1);
    }
    $env = [];
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $val = trim(substr($line, $pos + 1));
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[strlen($val) - 1] === $val[0]) {
            $val = substr($val, 1, -1);
        }
        $env[trim(substr($line, 0, $pos))] = $val;
    }

    $before = trim($env['REDDIT_SESSION_COOKIE'] ?? '');
    $fresh = refreshRedditSessionCookie($envPath, $env);
    if ($fresh === null) {
        fwrite(STDERR, "refresh_cookie: could not read/decrypt the live Chrome cookie; .env left unchanged.\n");
        fwrite(STDERR, "  Check: REDDIT_COOKIE_AUTOREFRESH is not false, REDDIT_CHROME_PROFILE is correct, you are logged into the desktop (keyring unlocked).\n");
        exit(1);
    }
    if ($fresh === $before) {
        echo "refresh_cookie: cookie already current; .env unchanged.\n";
    } else {
        echo "refresh_cookie: updated REDDIT_SESSION_COOKIE in .env.\n";
    }
    exit(0);
}
