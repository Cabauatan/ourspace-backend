<?php

use App\Models\CoupleProfile;
use App\Models\PartnerCall;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('ourspace:notifications')->everyMinute()->withoutOverlapping(5);
Schedule::command('ourspace:calls-prune')->everyMinute()->withoutOverlapping(5);

Artisan::command('ourspace:calls-prune', function (): void {
    PartnerCall::query()->whereIn('status', ['ringing', 'active'])->select('couple_id')->distinct()->get()->each(function ($item): void {
        DB::transaction(function () use ($item): void {
            CoupleProfile::query()->whereKey($item->couple_id)->lockForUpdate()->firstOrFail();
            PartnerCall::query()->where('couple_id', $item->couple_id)->whereIn('status', ['ringing', 'active'])->get()->each(fn ($call) => $call->expireIfStale());
        });
    });
    PartnerCall::query()->where('ended_at', '<', now()->subDays(30))->delete();
})->purpose('Expire abandoned calls, remove their signaling data, and retain 30 days of history');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
