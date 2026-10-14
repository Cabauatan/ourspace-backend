<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('couple_profiles', function (Blueprint $table): void {
            if (! Schema::hasIndex('couple_profiles', 'couple_profiles_setup_code_hash_index')) {
                $table->index('setup_code_hash');
            }
            if (! Schema::hasIndex('couple_profiles', 'couple_profiles_recovery_code_hash_index')) {
                $table->index('recovery_code_hash');
            }
        });
        Schema::create('partner_calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('couple_id')->constrained('couple_profiles')->restrictOnDelete();
            $table->foreignId('caller_device_id')->constrained('devices')->restrictOnDelete();
            $table->foreignId('callee_device_id')->constrained('devices')->restrictOnDelete();
            $table->uuid('client_id');
            $table->string('caller_partner', 20);
            $table->string('kind', 10);
            $table->string('status', 10);
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->dateTime('caller_seen_at');
            $table->timestamp('callee_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['caller_device_id', 'client_id']);
            $table->index(['couple_id', 'status']);
        });
        Schema::create('call_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('call_id')->constrained('partner_calls')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();
            $table->string('type', 10);
            $table->longText('payload');
            $table->dateTime('created_at');
            $table->index(['call_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_signals');
        Schema::dropIfExists('partner_calls');
        Schema::table('couple_profiles', function (Blueprint $table): void {
            $table->dropIndex(['setup_code_hash']);
            $table->dropIndex(['recovery_code_hash']);
        });
    }
};
