<?php

namespace App\Models;

use Database\Factories\PartnerCallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PartnerCall extends Model
{
    /** @use HasFactory<PartnerCallFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'ended_at' => 'datetime', 'caller_seen_at' => 'datetime', 'callee_seen_at' => 'datetime'];
    }

    public function expireIfStale(): void
    {
        if (! in_array($this->status, ['ringing', 'active'], true)) {
            return;
        }
        $phones = Device::query()->whereIn('id', [$this->caller_device_id, $this->callee_device_id])->whereNull('revoked_at')->count();
        if ($phones !== 2 || ($this->status === 'ringing' && $this->created_at->lte(now()->subSeconds(45))) || ($this->status === 'active' && ($this->caller_seen_at->lte(now()->subSeconds(45)) || $this->callee_seen_at?->lte(now()->subSeconds(45))))) {
            $this->forceFill(['status' => $this->status === 'ringing' && $phones === 2 ? 'missed' : 'failed', 'ended_at' => now()])->save();
            DB::table('call_signals')->where('call_id', $this->id)->delete();
        }
    }
}
