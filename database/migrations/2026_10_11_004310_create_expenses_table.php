<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('couple_id')->constrained('couple_profiles')->cascadeOnDelete();
            $table->string('description', 200);
            $table->unsignedBigInteger('amount')->comment('PHP centavos');
            $table->string('category', 30);
            $table->string('paid_by', 20);
            $table->date('expense_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['couple_id', 'expense_date', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
