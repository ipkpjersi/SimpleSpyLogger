<?php
/**
 * Shared alert helper for the SimpleSpyLogger scrapers.
 *
 * Two channels, each opt-in by populating its env vars:
 *  - SMTP email via Symfony Mailer (reused from laravel/vendor), keyed off
 *    Laravel's MAIL_* env shape plus MAIL_TO for the recipient.
 *  - Discord webhook, keyed off DISCORD_WEBHOOK_URL.
 *
 * send_alert() dispatches to both channels; each silently no-ops when its
 * config is missing. Both channels are independent: either, neither, or
 * both can be enabled per scraper.
 */

$autoload = __DIR__.'/../laravel/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

function send_alert_email(array $env, string $subject, string $body): bool
{
    $host = $env['MAIL_HOST'] ?? '';
    $to = $env['MAIL_TO'] ?? '';
    if ($host === '' || strtolower($host) === 'null' || $to === '' || strtolower($to) === 'null') {
        return false;
    }

    if (! class_exists(\Symfony\Component\Mailer\Mailer::class)) {
        fwrite(STDERR, "send_alert_email: Symfony Mailer not autoloaded (expected at laravel/vendor/autoload.php)\n");

        return false;
    }

    $port = (int) ($env['MAIL_PORT'] ?? 587);
    $user = (string) ($env['MAIL_USERNAME'] ?? '');
    $pass = (string) ($env['MAIL_PASSWORD'] ?? '');
    $scheme = (string) ($env['MAIL_SCHEME'] ?? '');
    if (strtolower($user) === 'null') {
        $user = '';
    }
    if (strtolower($pass) === 'null') {
        $pass = '';
    }
    if ($scheme === '' || strtolower($scheme) === 'null') {
        $scheme = $port === 465 ? 'smtps' : 'smtp';
    }

    $auth = ($user !== '' && $pass !== '')
        ? rawurlencode($user).':'.rawurlencode($pass).'@'
        : '';
    $dsn = $scheme.'://'.$auth.$host.':'.$port;

    $from = (string) ($env['MAIL_FROM_ADDRESS'] ?? '');
    if ($from === '' || strtolower($from) === 'null') {
        $from = 'simplespylogger@localhost';
    }
    $fromName = (string) ($env['MAIL_FROM_NAME'] ?? 'SimpleSpyLogger');

    try {
        $transport = \Symfony\Component\Mailer\Transport::fromDsn($dsn);
        $mailer = new \Symfony\Component\Mailer\Mailer($transport);
        $email = (new \Symfony\Component\Mime\Email())
            ->from(new \Symfony\Component\Mime\Address($from, $fromName))
            ->to($to)
            ->subject($subject)
            ->text($body);
        $mailer->send($email);

        return true;
    } catch (\Throwable $e) {
        fwrite(STDERR, 'send_alert_email failed: '.$e->getMessage()."\n");

        return false;
    }
}

function send_discord_webhook(array $env, string $subject, string $body): bool
{
    $url = (string) ($env['DISCORD_WEBHOOK_URL'] ?? '');
    if ($url === '' || strtolower($url) === 'null') {
        return false;
    }

    $header = "**$subject**\n```\n";
    $footer = "\n```";
    // Discord content cap is 2000 chars. Leave a margin for header/footer.
    $maxBody = 2000 - mb_strlen($header) - mb_strlen($footer) - 20;
    if (mb_strlen($body) > $maxBody) {
        $body = mb_substr($body, 0, $maxBody)."\n... (truncated)";
    }

    $payload = json_encode([
        'content' => $header.$body.$footer,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'SimpleSpyLogger-Notifier/1.0',
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        fwrite(STDERR, 'send_discord_webhook failed: '.$err."\n");

        return false;
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) {
        fwrite(STDERR, "send_discord_webhook: HTTP $code response: ".mb_substr((string) $resp, 0, 200)."\n");

        return false;
    }

    return true;
}

function send_alert(array $env, string $subject, string $body): array
{
    return [
        'email' => send_alert_email($env, $subject, $body),
        'discord' => send_discord_webhook($env, $subject, $body),
    ];
}
