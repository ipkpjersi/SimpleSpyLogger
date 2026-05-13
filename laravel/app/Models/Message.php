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

    public function scopeForSource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }
}
