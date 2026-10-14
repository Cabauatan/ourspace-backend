<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['description', 'amount', 'category', 'paid_by', 'expense_date', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'expense_date' => 'date'];
    }
}
