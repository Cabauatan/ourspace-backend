<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }
}
