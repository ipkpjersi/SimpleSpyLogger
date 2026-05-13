<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class PasswordSecurity extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
