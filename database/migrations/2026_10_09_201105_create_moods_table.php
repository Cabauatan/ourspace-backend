<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('couple_id')->constrained('couple_profiles')->restrictOnDelete();
            $table->enum('partner_type', ['partner_one', 'partner_two']);
            $table->string('mood', 20);
            $table->text('note')->nullable();
            $table->date('mood_date');
            $table->timestamps();
            $table->unique(['couple_id', 'partner_type', 'mood_date']);
            $table->index(['couple_id', 'mood_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moods');
    }
};
