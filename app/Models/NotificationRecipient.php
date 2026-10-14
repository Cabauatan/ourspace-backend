<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class NotificationRecipient extends Model
{
    use Notifiable;

    protected $fillable = ['couple_id', 'partner_type', 'enabled_types'];

    protected function casts(): array
    {
        return ['enabled_types' => 'array'];
    }
}
