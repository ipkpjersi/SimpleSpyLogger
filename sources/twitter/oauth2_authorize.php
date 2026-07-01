<?php
/**
 * SimpleSpyLogger - one-time OAuth 2.0 authorization for a Twitter/X account.
 *
 * OAuth 2.0 Authorization Code with PKCE needs a one-time, per-account browser
 * approval to mint the first refresh token. This helper walks you through it and
 * writes TWITTER_A{n}_REFRESH_TOKEN back into .env; download.php then refreshes
 * that token automatically on every run (no browser needed again unless the
 * refresh token is revoked or goes 90+ days unused).
 *
 * Prerequisites in the X developer portal, per app:
 *   - User authentication settings enabled, App type with a client secret
 *     (Web App / Automated App / Bot -> "Confidential client").
 *   - A Callback URI / Redirect URL registered that EXACTLY matches
 *     TWITTER_A{n}_REDIRECT_URI (default http://localhost).
 *   - Client ID and Client Secret copied into .env for that account.
 *
 * Usage:
 *   php oauth2_authorize.php A1            authorize account slot A1
 *   php oauth2_authorize.php 2             same as A2
 *   php oauth2_authorize.php account_username  select by username
 *
 * The requested scopes are read-only: tweet.read users.read dm.read plus
 * offline.access (required to receive a refresh token).
 */

$root = __DIR__;
require __DIR__.'/oauth2.php';

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

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);

    return $line === false ? '' : trim($line);
}

$envPath = $root.'/.env';
$env = load_env($envPath);

$sel = trim($argv[1] ?? '');
if ($sel === '') {
    fwrite(STDERR, "Usage: php oauth2_authorize.php <A1|1|username>\n");
    exit(1);
}

// Resolve the account slot index from "A1", "1", or a username.
$index = null;
if (preg_match('/^A?(\d+)$/i', $sel, $m)) {
    $index = (int) $m[1];
} else {
    foreach (array_keys($env) as $key) {
        if (preg_match('/^TWITTER_A(\d+)_USERNAME$/', $key, $mm) && strcasecmp(trim($env[$key]), $sel) === 0) {
            $index = (int) $mm[1];
            break;
        }
    }
}
if ($index === null) {
    fwrite(STDERR, "Could not resolve account '$sel'. Pass a slot like A1 or a configured username.\n");
    exit(1);
}

$p = "TWITTER_A{$index}_";
$username = trim($env[$p.'USERNAME'] ?? '');
$clientId = trim($env[$p.'CLIENT_ID'] ?? '');
$clientSecret = trim($env[$p.'CLIENT_SECRET'] ?? '');
$redirectUri = trim($env[$p.'REDIRECT_URI'] ?? '') ?: 'http://localhost';

if ($username === '') {
    fwrite(STDERR, "{$p}USERNAME is not set.\n");
    exit(1);
}
if ($clientId === '' || $clientSecret === '') {
    fwrite(STDERR, "{$p}CLIENT_ID and {$p}CLIENT_SECRET must be set before authorizing.\n");
    exit(1);
}

$scope = 'tweet.read users.read dm.read offline.access';
[$verifier, $challenge] = twitter_pkce_pair();
$state = bin2hex(random_bytes(16));

$authorizeUrl = 'https://twitter.com/i/oauth2/authorize?'.http_build_query([
    'response_type' => 'code',
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'scope' => $scope,
    'state' => $state,
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
]);

echo "\nAuthorizing @$username (slot A$index), scopes: $scope\n\n";
echo "1. Open this URL in a browser logged into @$username:\n\n";
echo "   $authorizeUrl\n\n";
echo "2. Approve access. Your browser will redirect to\n";
echo "   $redirectUri/?code=...&state=...  (it may show a 'cannot connect' page;\n";
echo "   that is fine - the code is in the address bar).\n\n";
echo "3. Paste the FULL redirected URL (or just the code) below.\n\n";

$input = prompt('Redirected URL or code: ');
if ($input === '') {
    fwrite(STDERR, "No input given.\n");
    exit(1);
}

// Accept either the whole redirected URL or a bare code.
$code = $input;
if (strpos($input, 'code=') !== false) {
    $qs = parse_url($input, PHP_URL_QUERY) ?: $input;
    parse_str($qs, $parts);
    $code = $parts['code'] ?? '';
    if (isset($parts['state']) && $parts['state'] !== $state) {
        fwrite(STDERR, "State mismatch (expected $state, got {$parts['state']}). Aborting - re-run to try again.\n");
        exit(1);
    }
}
if ($code === '') {
    fwrite(STDERR, "Could not find an authorization code in the input.\n");
    exit(1);
}

$resp = twitter_oauth2_token([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => $redirectUri,
    'code_verifier' => $verifier,
    'client_id' => $clientId,
], $clientId, $clientSecret);

if (! $resp['ok'] || ! isset($resp['data']['refresh_token'])) {
    fwrite(STDERR, 'Token exchange failed (HTTP '.$resp['code'].'): '.mb_substr($resp['raw'], 0, 400)."\n");
    if ($resp['code'] === 400) {
        fwrite(STDERR, "Hint: a 400 here usually means the redirect_uri did not exactly match the app's registered Callback URI, or the code was already used/expired. Re-run and paste a fresh code.\n");
    }
    exit(1);
}

$refreshToken = (string) $resp['data']['refresh_token'];
$grantedScope = (string) ($resp['data']['scope'] ?? '');

if (! twitter_env_set($envPath, $p.'REFRESH_TOKEN', $refreshToken)) {
    fwrite(STDERR, "Got a refresh token but failed to write it to $envPath. Add manually:\n{$p}REFRESH_TOKEN=$refreshToken\n");
    exit(1);
}

echo "\nSuccess. Wrote {$p}REFRESH_TOKEN to .env.\n";
echo "Granted scopes: $grantedScope\n";
if (strpos($grantedScope, 'dm.read') === false) {
    echo "WARNING: dm.read was not granted - DM reads will 403. Re-authorize and approve the DM scope.\n";
}
if (strpos($grantedScope, 'offline.access') === false) {
    echo "WARNING: offline.access was not granted - without it the refresh token will not work for unattended runs.\n";
}
echo "\nYou can now run: php download.php no-delay\n";
