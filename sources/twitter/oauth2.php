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
    $tmp = $path.'.tmp';
    if (file_put_contents($tmp, implode("\n", $out)."\n", LOCK_EX) === false) {
        return false;
    }

    return rename($tmp, $path);
}
