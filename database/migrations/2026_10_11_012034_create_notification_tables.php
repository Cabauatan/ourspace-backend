<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('couple_id')->constrained('couple_profiles')->restrictOnDelete();
            $table->string('partner_type', 20);
            $table->json('enabled_types')->nullable();
            $table->timestamps();
            $table->unique(['couple_id', 'partner_type']);
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('push_processed_at')->nullable();
            $table->timestamps();
        });
        Schema::table('devices', function (Blueprint $table): void {
            $table->text('push_token')->nullable();
            $table->string('push_token_hash', 64)->nullable()->unique();
            $table->string('notification_mode', 10)->default('off');
        });
        Schema::create('notification_push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();
            $table->timestamp('sent_at');
            $table->unique(['notification_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_push_deliveries');
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropUnique(['push_token_hash']);
            $table->dropColumn(['push_token', 'push_token_hash', 'notification_mode']);
        });
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_recipients');
    }
};
