<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'content',
        'payload',
        'source_edited_at',
        'captured_at',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'source_edited_at' => 'datetime',
        'captured_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
