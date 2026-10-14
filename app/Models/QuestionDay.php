<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionDay extends Model
{
    use HasFactory;

    protected $fillable = [];

    protected function questionDate(): Attribute
    {
        return Attribute::make(set: fn (string|\DateTimeInterface $value): string => CarbonImmutable::parse($value)->toDateString());
    }

    protected function casts(): array
    {
        return ['question_date' => 'date'];
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuestionAnswer::class, 'question_day_id');
    }
}
