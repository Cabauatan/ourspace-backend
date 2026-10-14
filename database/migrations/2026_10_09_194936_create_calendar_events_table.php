<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('couple_id')->constrained('couple_profiles')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->enum('event_type', ['date', 'anniversary', 'birthday', 'travel', 'reminder', 'personal', 'other']);
            $table->date('start_date');
            $table->date('end_date');
            $table->dateTime('reminder_at')->nullable();
            $table->dateTime('reminder_dismissed_at')->nullable();
            $table->enum('created_by_partner', ['partner_one', 'partner_two']);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['couple_id', 'deleted_at', 'start_date', 'end_date'], 'calendar_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
