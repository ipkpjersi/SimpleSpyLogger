<?php
/**
 * SimpleSpyLogger - RSCPlus log scraper
 *
 * Reads the most recently modified *.log file in the RSCPlus logs folder and
 * inserts public chat, global chat and friend login/logout lines into the
 * SimpleSpyLogger messages table via PDO.
 *
 * Three RSCPlus line types are logged: "(  CHAT)", global chat (the "Global$"
 * lines, which RSCPlus emits under the "( QUEST)" prefix) and private message
 * bodies ("( PM_IN)" / "(PM_OUT)"). Friend login/logout notices ("(PM_LOG)")
 * are skipped.
 *
 * RSCPlus chat logs have no per-line timestamps, so sent_at is taken from the
 * session-start time encoded in the log filename.
 *
 * Usage: php download.php [/path/to/logs/dir]
 *   The optional argument overrides RSCPLUS_LOGS_DIR from .env.
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

$env = load_env($root.'/.env');

date_default_timezone_set($env['APP_TIMEZONE'] ?? 'UTC');

$logsDir = $argv[1] ?? ($env['RSCPLUS_LOGS_DIR'] ?? '');
if ($logsDir === '' || ! is_dir($logsDir)) {
    fwrite(STDERR, "RSCPLUS_LOGS_DIR is not set or not a directory: '$logsDir'\n");
    exit(1);
}

$world = $env['RSCPLUS_WORLD'] ?? 'preservation';
$worldName = $env['RSCPLUS_WORLD_NAME'] ?? 'Preservation';

// The local player's RSC name - used as the author of outgoing PMs, which the
// log records only as "You tell <name>: ...".
$playerName = $env['RSCPLUS_PLAYER_NAME'] ?? 'You';

// Most recently modified *.log file in the folder.
$logs = glob(rtrim($logsDir, '/').'/*.log');
if (! $logs) {
    fwrite(STDERR, "No *.log files found in $logsDir\n");
    exit(1);
}
usort($logs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
$logFile = $logs[0];
$logName = basename($logFile);

// Session start time from the filename: rscplus_YYYY-MM-DD_HH-MM-SS.log
$sentAt = null;
if (preg_match('/(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})/', $logName, $m)) {
    $sentAt = "{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}";
}
$capturedAt = date('Y-m-d H:i:s');

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

// RSC chat colour codes look like @cya@, @gre@, @ran@ - strip them everywhere.
function strip_colours(string $s): string
{
    return preg_replace('/@[a-z0-9]{3}@/i', '', $s);
}

// chat type => [channel_external_id, channel_name, visibility]
$channels = [
    'public' => ['public', 'Public chat', 'public'],
    'global' => ['global', 'Global chat', 'public'],
    'private' => ['private', 'Private message', 'private'],
];

$lines = file($logFile, FILE_IGNORE_NEW_LINES);
$matched = 0;
$inserted = 0;

foreach ($lines as $i => $raw) {
    $lineNo = $i + 1;
    $clean = strip_colours($raw);

    $direction = null;
    $recipient = null;

    // The tag is padded with spaces inside the parens (eg "(  CHAT)",
    // "( PM_IN)"); \s* on both sides tolerates any padding count.
    if (preg_match('/^\(\s*CHAT\s*\) ([^:]+): (.*)$/', $clean, $m)) {
        $type = 'public';
        $author = trim($m[1]);
        $content = $m[2];
    } elseif (preg_match('/^\(\s*QUEST\s*\) Global\$\[(.+?)\]: (.*)$/', $clean, $m)) {
        $type = 'global';
        $author = trim($m[1]);
        $content = $m[2];
    } elseif (preg_match('/^\(\s*PM_IN\s*\) (.+) tells you: (.*)$/', $clean, $m)) {
        $type = 'private';
        $direction = 'in';
        $author = trim($m[1]);
        $content = $m[2];
        $recipient = $playerName;
    } elseif (preg_match('/^\(\s*PM_OUT\s*\) You tell (.+?): (.*)$/', $clean, $m)) {
        $type = 'private';
        $direction = 'out';
        $author = $playerName;
        $content = $m[2];
        $recipient = trim($m[1]);
    } else {
        continue;
    }

    $matched++;

    [$channelId, $channelName, $visibility] = $channels[$type];

    // Stable per (log file, line) so re-runs on the same/growing file are idempotent.
    $externalId = $world.':'.hash('sha256', $logName.'#'.$lineNo.'#'.$raw);

    $payload = json_encode(array_filter([
        'log_file' => $logName,
        'line' => $lineNo,
        'chat_type' => $type,
        'direction' => $direction,
        'recipient' => $recipient,
        'raw' => $raw,
    ], fn ($v) => $v !== null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    try {
        $insert->execute([
            ':source' => 'rscplus',
            ':external_id' => $externalId,
            ':container_external_id' => $world,
            ':container_name' => $worldName,
            ':channel_external_id' => $channelId,
            ':channel_name' => $channelName,
            ':visibility' => $visibility,
            ':author_external_id' => $author,
            ':author_username' => $author,
            ':author_display_name' => null,
            ':author_bot' => 0,
            ':content' => $content,
            ':referenced_external_id' => null,
            ':sent_at' => $sentAt,
            ':source_edited_at' => null,
            ':deleted_at' => null,
            ':captured_at' => $capturedAt,
            ':payload' => $payload,
            ':created_at' => $capturedAt,
            ':updated_at' => $capturedAt,
        ]);
        $inserted += $insert->rowCount();
    } catch (PDOException $e) {
        fwrite(STDERR, "Skipped line $lineNo: ".$e->getMessage()."\n");
    }
}

echo "RSCPlus: $logName - matched $matched chat lines, inserted $inserted new.\n";
