<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalendarEvent extends Model
{
    use SoftDeletes;

    protected $fillable = ['title', 'description', 'event_type', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'reminder_at' => 'datetime', 'reminder_dismissed_at' => 'datetime'];
    }
}
