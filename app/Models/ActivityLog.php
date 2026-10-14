<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['couple_id', 'partner_type', 'type', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public static function record(Device $actor, string $type): void
    {
        static::query()->create(['couple_id' => $actor->couple_id, 'partner_type' => $actor->partner_type, 'type' => $type, 'created_at' => now()]);
    }
}
