<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('couple_profiles', function (Blueprint $table): void {
            $table->date('partner_one_birthday')->nullable();
            $table->date('partner_two_birthday')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('couple_profiles', function (Blueprint $table): void {
            $table->dropColumn(['partner_one_birthday', 'partner_two_birthday']);
        });
    }
};
