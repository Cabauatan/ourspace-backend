<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoupleProfile extends Model
{
    use HasFactory;

    protected $fillable = ['partner_one_name', 'partner_two_name', 'relationship_start_date', 'anniversary_date', 'partner_one_birthday', 'partner_two_birthday'];

    protected $hidden = ['setup_code_hash', 'setup_code_expires_at', 'recovery_code_hash'];

    protected function casts(): array
    {
        return ['relationship_start_date' => 'date', 'anniversary_date' => 'date', 'partner_one_birthday' => 'date', 'partner_two_birthday' => 'date', 'setup_code_expires_at' => 'datetime'];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'couple_id');
    }
}
