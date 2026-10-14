<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SharedTask extends Model
{
    use SoftDeletes;

    protected $table = 'tasks';

    protected $fillable = ['title', 'description', 'assigned_to', 'priority', 'due_date'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'completed_at' => 'datetime'];
    }

    public static function today(): string
    {
        return now(config('tasks.timezone'))->toDateString();
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'completed' && $this->due_date !== null && $this->due_date->format('Y-m-d') < static::today();
    }
}
