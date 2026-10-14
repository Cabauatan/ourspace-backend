<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('memory_tags', fn (Blueprint $table) => $table->string('name', 40)->collation('utf8mb4_bin')->change());
        }
    }

    public function down(): void
    {
        // Keep the exact tag collation: reverting can collapse existing accent-distinct tags.
    }
};
