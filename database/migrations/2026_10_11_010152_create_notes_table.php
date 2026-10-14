<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('couple_id')->constrained('couple_profiles')->cascadeOnDelete();
            $table->string('title', 200);
            $table->longText('content');
            $table->string('created_by_partner', 20);
            $table->string('visibility', 30);
            $table->boolean('pinned')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['couple_id', 'visibility', 'pinned', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
