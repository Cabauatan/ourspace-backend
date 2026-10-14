<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    use HasFactory;

    protected $fillable = ['device_name', 'device_identifier'];

    protected $hidden = ['token_hash', 'push_token', 'push_token_hash'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'registered_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime', 'push_token' => 'encrypted'];
    }

    public function couple(): BelongsTo
    {
        return $this->belongsTo(CoupleProfile::class, 'couple_id');
    }
}
