<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $fillable = [
        'source',
        'external_id',
        'container_external_id',
        'container_name',
        'channel_external_id',
        'channel_name',
        'visibility',
        'author_external_id',
        'author_username',
        'author_display_name',
        'author_bot',
        'content',
        'referenced_external_id',
        'sent_at',
        'source_edited_at',
        'deleted_at',
        'captured_at',
        'payload',
    ];

    protected $casts = [
        'author_bot' => 'boolean',
        'payload' => 'array',
        'sent_at' => 'datetime',
        'source_edited_at' => 'datetime',
        'deleted_at' => 'datetime',
        'captured_at' => 'datetime',
    ];

    public function revisions(): HasMany
    {
        return $this->hasMany(MessageRevision::class);
    }

    // Permalink back to the original message on the source service, derived
    // from data already stored (no separate column). Returns null for sources
    // with no web URL, e.g. rscplus in-game chat.
    public function getUrlAttribute(): ?string
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        switch ($this->source) {
            case 'discord':
                if (($this->external_id ?? '') === '' || ($this->channel_external_id ?? '') === '') {
                    return null;
                }
                // DMs have no guild; Discord uses the literal "@me" there.
                $guild = ($this->container_external_id ?? '') !== '' ? $this->container_external_id : '@me';

                return "https://discord.com/channels/{$guild}/{$this->channel_external_id}/{$this->external_id}";

            case 'reddit':
                $permalink = $payload['permalink'] ?? null;
                if (! is_string($permalink) || $permalink === '') {
                    return null;
                }

                return 'https://www.reddit.com'.$permalink;

            case 'twitter':
                // DMs are private and have no public web URL; tweets link to the
                // author's status page. Need both the handle and the tweet id.
                $kind = $payload['kind'] ?? null;
                if ($kind === 'dm' || ($this->author_username ?? '') === '' || ($this->external_id ?? '') === '') {
                    return null;
                }

                return "https://x.com/{$this->author_username}/status/{$this->external_id}";

            case 'lemmy':
                // ap_id is the canonical ActivityPub URL of the comment. Require
                // an http(s) scheme so a malformed value can't become a link.
                $apId = $payload['ap_id'] ?? null;
                if (! is_string($apId) || ! preg_match('#^https?://#i', $apId)) {
                    return null;
                }

                return $apId;

            default:
                return null;
        }
    }

    public function scopeForSource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }
}
