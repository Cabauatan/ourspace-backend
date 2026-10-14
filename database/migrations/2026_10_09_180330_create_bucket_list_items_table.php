<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bucket_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('couple_id')->constrained('couple_profiles')->restrictOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('category', 60);
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->date('target_date')->nullable();
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->enum('created_by_partner', ['partner_one', 'partner_two']);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['couple_id', 'deleted_at', 'status', 'target_date'], 'bucket_list_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bucket_list_items');
    }
};
