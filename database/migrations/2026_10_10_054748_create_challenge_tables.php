<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('title');
            $table->text('description');
            $table->string('category', 30);
            $table->string('difficulty', 20);
            $table->unsignedInteger('points');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('couple_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('couple_id')->constrained('couple_profiles')->cascadeOnDelete();
            $table->foreignId('challenge_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('category', 30);
            $table->string('difficulty', 20);
            $table->unsignedInteger('points');
            $table->string('status', 20)->default('in_progress');
            $table->timestamp('completed_at')->nullable();
            $table->string('completed_by_partner', 20)->nullable();
            $table->string('proof_photo')->nullable();
            $table->string('proof_mime')->nullable();
            $table->unsignedBigInteger('proof_size')->nullable();
            $table->string('proof_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['couple_id', 'challenge_id']);
            $table->index(['couple_id', 'status', 'id']);
        });

        Schema::create('achievements', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('title');
            $table->text('description');
            $table->string('badge', 30);
            $table->string('metric', 30);
            $table->unsignedInteger('threshold');
            $table->timestamps();
        });

        Schema::create('couple_achievements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('couple_id')->constrained('couple_profiles')->cascadeOnDelete();
            $table->foreignId('achievement_id')->constrained()->restrictOnDelete();
            $table->timestamp('unlocked_at');
            $table->unique(['couple_id', 'achievement_id']);
        });
    }

    public function down(): void
    {
        foreach (['couple_achievements', 'achievements', 'couple_challenges', 'challenges'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
