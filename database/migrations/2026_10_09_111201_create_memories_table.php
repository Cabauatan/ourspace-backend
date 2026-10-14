<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('couple_id')->constrained('couple_profiles')->restrictOnDelete();
            $table->enum('created_by_partner', ['partner_one', 'partner_two']);
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->date('memory_date');
            $table->string('location_name', 150)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('favorite')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['couple_id', 'deleted_at', 'memory_date', 'id']);
        });
        Schema::create('memory_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('memory_id')->constrained('memories')->cascadeOnDelete();
            $table->string('file_path');
            $table->enum('media_type', ['image', 'video']);
            $table->string('mime_type', 40);
            $table->unsignedBigInteger('file_size');
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();
            $table->unique(['memory_id', 'sort_order']);
        });
        Schema::create('memory_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('memory_id')->constrained('memories')->cascadeOnDelete();
            $table->string('name', 40);
            $table->unique(['memory_id', 'name']);
            $table->index(['name', 'memory_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_tags');
        Schema::dropIfExists('memory_media');
        Schema::dropIfExists('memories');
    }
};
