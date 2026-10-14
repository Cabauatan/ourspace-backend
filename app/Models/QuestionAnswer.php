<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionAnswer extends Model
{
    use HasFactory;

    protected $fillable = ['answer'];

    protected function answerDate(): Attribute
    {
        return Attribute::make(set: fn (string|\DateTimeInterface $value): string => CarbonImmutable::parse($value)->toDateString());
    }

    protected function casts(): array
    {
        return ['answer_date' => 'date'];
    }
}
