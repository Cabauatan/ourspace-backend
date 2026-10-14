<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BucketListItem extends Model
{
    use SoftDeletes;

    protected $fillable = ['title', 'description', 'category', 'priority', 'target_date', 'notes'];

    protected function casts(): array
    {
        return ['target_date' => 'date', 'completed_at' => 'datetime'];
    }

    public function media(): HasMany
    {
        return $this->hasMany(BucketListMedia::class)->orderBy('sort_order')->orderBy('id');
    }
}
