<?php

namespace Database\Seeders;

use App\Models\CoupleProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PhaseTwoPreviewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing') || DB::connection()->getDatabaseName() !== 'ourspace_phase2_preview') {
            throw new \LogicException('Preview fixtures are restricted to the isolated ourspace_phase2_preview database.');
        }

        if (CoupleProfile::query()->exists()) {
            throw new \LogicException('Preview is already initialized; existing data will not be overwritten.');
        }

        CoupleProfile::factory()->create([
            'id' => 1,
            'partner_one_name' => 'Demo Partner One',
            'partner_two_name' => 'Demo Partner Two',
        ]);
    }
}
