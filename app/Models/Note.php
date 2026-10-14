<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Note extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['title', 'content', 'pinned'];

    protected function casts(): array
    {
        return ['pinned' => 'boolean'];
    }

    public function scopeVisibleTo(Builder $query, Device $actor): Builder
    {
        return $query->where('couple_id', $actor->couple_id)->whereIn('visibility', ['shared', $actor->partner_type.'_private']);
    }
}
