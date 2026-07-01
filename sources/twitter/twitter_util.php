<?php
/**
 * Shared helpers for the twitter source scripts (importer, scraper, resolver).
 */

/**
 * The other participant in a 1:1 DM. Twitter's 1:1 conversation ids are
 * "userA-userB"; the partner is whichever id is not us (the owner). Group
 * conversations use a single opaque id, so they have no single partner (null).
 */
function dm_partner_id(string $conversationId, string $ownerId, bool $isGroup = false): ?string
{
    if ($isGroup || $conversationId === '' || strpos($conversationId, '-') === false) {
        return null;
    }
    $parts = explode('-', $conversationId);
    if (count($parts) !== 2) {
        return null;
    }
    foreach ($parts as $pid) {
        if ($pid !== '' && $pid !== $ownerId) {
            return $pid;
        }
    }

    return null;
}

/**
 * Load the twitter "external user id -> username" map from external_users into
 * an associative array, for naming DM partners without re-hitting the API. Only
 * resolved (non-null) usernames are included.
 */
function load_twitter_username_map(PDO $pdo): array
{
    $map = [];
    $sql = "SELECT external_id, username FROM external_users
             WHERE source = 'twitter' AND username IS NOT NULL AND username <> ''";
    foreach ($pdo->query($sql) as $row) {
        $map[(string) $row['external_id']] = (string) $row['username'];
    }

    return $map;
}

/**
 * The stored channel label for a DM: the partner's @handle when resolved,
 * otherwise their bare id (or "DM group"/"DM" when there is no single partner).
 * The "(deleted)" annotation for unresolvable partners is applied at display
 * time, not stored here.
 */
function dm_channel_name(?string $partnerId, bool $isGroup, array $usernameMap): string
{
    if ($isGroup) {
        return 'DM group';
    }
    if ($partnerId === null || $partnerId === '') {
        return 'DM';
    }

    return isset($usernameMap[$partnerId]) ? '@'.$usernameMap[$partnerId] : $partnerId;
}
