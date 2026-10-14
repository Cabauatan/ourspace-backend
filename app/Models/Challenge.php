<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Challenge extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'title', 'description', 'category', 'difficulty', 'points', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'points' => 'integer'];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CoupleChallenge::class);
    }
}
