<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bucket_list_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bucket_list_item_id')->constrained('bucket_list_items')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('mime_type', 50);
            $table->unsignedBigInteger('file_size');
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bucket_list_media');
    }
};
