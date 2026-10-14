<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_questions', function (Blueprint $table): void {
            $table->id();
            $table->string('question', 500)->unique();
            $table->string('category', 40);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['active', 'id']);
        });
        Schema::create('question_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('couple_id')->constrained('couple_profiles')->restrictOnDelete();
            $table->foreignId('question_id')->constrained('daily_questions')->restrictOnDelete();
            $table->string('question', 500);
            $table->string('category', 40);
            $table->date('question_date');
            $table->timestamps();
            $table->unique(['couple_id', 'question_date']);
        });
        Schema::create('question_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('couple_id')->constrained('couple_profiles')->restrictOnDelete();
            $table->foreignId('question_id')->constrained('daily_questions')->restrictOnDelete();
            $table->foreignId('question_day_id')->constrained('question_days')->restrictOnDelete();
            $table->enum('partner_type', ['partner_one', 'partner_two']);
            $table->text('answer');
            $table->date('answer_date');
            $table->timestamps();
            $table->unique(['question_day_id', 'partner_type']);
            $table->index(['couple_id', 'answer_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_answers');
        Schema::dropIfExists('question_days');
        Schema::dropIfExists('daily_questions');
    }
};
