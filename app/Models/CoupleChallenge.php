<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoupleChallenge extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['proof_photo', 'proof_hash'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'points' => 'integer', 'proof_size' => 'integer'];
    }
}
