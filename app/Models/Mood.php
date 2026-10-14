<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mood extends Model
{
    use HasFactory;

    public const OPTIONS = ['happy', 'loved', 'excited', 'tired', 'sad', 'upset', 'sick', 'stressed', 'neutral'];

    protected $fillable = ['mood', 'note'];

    protected function moodDate(): Attribute
    {
        return Attribute::make(set: fn (string|\DateTimeInterface $value): string => CarbonImmutable::parse($value)->toDateString());
    }

    protected function casts(): array
    {
        return ['mood_date' => 'date'];
    }
}
