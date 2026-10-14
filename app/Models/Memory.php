<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Memory extends Model
{
    use SoftDeletes;

    protected $fillable = ['title', 'description', 'memory_date', 'location_name', 'favorite', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return ['memory_date' => 'date', 'favorite' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function media(): HasMany
    {
        return $this->hasMany(MemoryMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(MemoryTag::class)->orderBy('name');
    }
}
