<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(DailyQuestionSeeder::class);
        $this->call(ChallengeSeeder::class);
        // The private couple is initialized through ourspace:setup, never a public user seed.
    }
}
