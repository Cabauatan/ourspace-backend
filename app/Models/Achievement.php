<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Achievement extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'title', 'description', 'badge', 'metric', 'threshold'];

    protected function casts(): array
    {
        return ['threshold' => 'integer'];
    }
}
