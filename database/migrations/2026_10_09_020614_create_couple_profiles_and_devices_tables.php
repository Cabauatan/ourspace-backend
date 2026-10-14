<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couple_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('partner_one_name', 80);
            $table->string('partner_two_name', 80);
            $table->string('partner_one_avatar')->nullable();
            $table->string('partner_two_avatar')->nullable();
            $table->date('relationship_start_date')->nullable();
            $table->date('anniversary_date')->nullable();
            $table->string('setup_code_hash', 64)->nullable();
            $table->timestamp('setup_code_expires_at')->nullable();
            $table->string('recovery_code_hash', 64);
            $table->timestamps();
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('couple_id')->constrained('couple_profiles')->restrictOnDelete();
            $table->enum('partner_type', ['partner_one', 'partner_two']);
            $table->string('device_name', 80);
            $table->uuid('device_identifier');
            $table->string('token_hash', 64)->unique();
            $table->boolean('is_primary')->default(true);
            $table->timestamp('registered_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['couple_id', 'partner_type', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
        Schema::dropIfExists('couple_profiles');
    }
};
