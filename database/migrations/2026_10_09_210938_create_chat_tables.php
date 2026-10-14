<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('couple_id')->unique()->constrained('couple_profiles')->cascadeOnDelete();
            $table->timestamps();
        });
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('partner_type', 20);
            $table->uuid('client_id');
            $table->text('body')->nullable();
            $table->string('payload_hash', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'partner_type', 'client_id']);
            $table->index(['conversation_id', 'id']);
        });
        Schema::create('message_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('media_type', 10);
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->timestamps();
        });
        Schema::create('message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('partner_type', 20);
            $table->string('emoji', 20);
            $table->timestamps();
            $table->unique(['message_id', 'partner_type']);
        });
        Schema::create('message_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('partner_type', 20);
            $table->timestamp('read_at');
            $table->unique(['message_id', 'partner_type']);
        });
    }

    public function down(): void
    {
        foreach (['message_reads', 'message_reactions', 'message_media', 'messages', 'conversations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
