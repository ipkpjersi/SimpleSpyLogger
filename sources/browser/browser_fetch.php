<?php

declare(strict_types=1);

/**
 * SimpleSpyLogger - PHP wrapper around browser_fetch.py.
 *
 * Fetches a URL through a real headless Chrome on a dedicated profile (see
 * browser_fetch.py for how and why). Scrapers require this file and call
 * browser_fetch() in place of their own HTTP client.
 *
 * One-time setup per site: log into it in the dedicated profile with
 *   python3 sources/browser/browser_fetch.py --login https://www.example.com/
 * (pass the same --profile as the scraper uses if it is not the default).
 */

/**
 * GET a URL via browser_fetch.py. Empty options fall back to the helper's
 * defaults. Returns the same shape as the scrapers' http_get().
 *
 * @param array{profile?: string, port?: int|string, binary?: string, timeout?: int|string} $opts
 * @return array{ok: bool, code: int, body: ?string, error: ?string}
 */
function browser_fetch(string $url, array $opts = []): array
{
    $cmd = ['python3', __DIR__.'/browser_fetch.py'];
    foreach (['profile', 'port', 'binary', 'timeout'] as $key) {
        $val = trim((string) ($opts[$key] ?? ''));
        if ($val !== '') {
            $cmd[] = '--'.$key;
            $cmd[] = $val;
        }
    }
    $cmd[] = $url;

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (! is_resource($proc)) {
        return ['ok' => false, 'code' => 0, 'body' => null, 'error' => 'could not launch python3 browser_fetch.py'];
    }
    $out = (string) stream_get_contents($pipes[1]);
    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $result = json_decode($out, true);
    if (! is_array($result)) {
        $detail = $stderr !== '' ? $stderr : mb_substr($out, 0, 300);

        return ['ok' => false, 'code' => 0, 'body' => null, 'error' => 'browser_fetch.py returned no JSON: '.$detail];
    }
    if (isset($result['error'])) {
        return ['ok' => false, 'code' => 0, 'body' => null, 'error' => (string) $result['error']];
    }
    $code = (int) ($result['code'] ?? 0);

    return ['ok' => $code > 0 && $code < 400, 'code' => $code, 'body' => (string) ($result['body'] ?? ''), 'error' => null];
}
