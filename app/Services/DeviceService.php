<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\Device;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeviceService
{
    public function refreshUnpairedSetupCode(callable $export): CarbonInterface
    {
        return DB::transaction(function () use ($export): CarbonInterface {
            $couple = CoupleProfile::query()->whereKey(1)->lockForUpdate()->first();
            if (! $couple) {
                throw ValidationException::withMessages(['couple' => ['Initialize OurSpace before refreshing its setup invite.']]);
            }
            if ($couple->devices()->exists()) {
                throw ValidationException::withMessages(['couple' => ['A phone has already joined this couple. Use its recovery code to reconnect.']]);
            }

            $code = 'setup-'.bin2hex(random_bytes(32));
            $expiresAt = now()->addHours(24);
            $couple->setup_code_hash = hash('sha256', $code);
            $couple->setup_code_expires_at = $expiresAt;
            $couple->save();
            $export($code, $expiresAt);

            return $expiresAt;
        });
    }

    public function initialize(string $partnerOne, string $partnerTwo): array
    {
        return DB::transaction(function () use ($partnerOne, $partnerTwo): array {
            if (CoupleProfile::query()->exists()) {
                throw ValidationException::withMessages(['couple' => ['OurSpace is already initialized.']]);
            }
            $setupCode = 'setup-'.bin2hex(random_bytes(32));
            $recoveryCode = 'recovery-'.bin2hex(random_bytes(32));
            $couple = new CoupleProfile(['partner_one_name' => $partnerOne, 'partner_two_name' => $partnerTwo]);
            $couple->id = 1;
            $couple->setup_code_hash = hash('sha256', $setupCode);
            $couple->setup_code_expires_at = now()->addHours(24);
            $couple->recovery_code_hash = hash('sha256', $recoveryCode);
            $couple->save();

            return ['couple' => $couple, 'setup_code' => $setupCode, 'recovery_code' => $recoveryCode];
        });
    }

    public function register(array $input, bool $replace = false): array
    {
        return DB::transaction(function () use ($input, $replace): array {
            $hash = hash('sha256', $input['code']);
            $couple = CoupleProfile::query()->where(function ($query) use ($hash): void {
                $query->where('setup_code_hash', $hash)->orWhere('recovery_code_hash', $hash);
            })->lockForUpdate()->first();
            $isRecovery = $couple && hash_equals($couple->recovery_code_hash, $hash);
            $isSetup = $couple && $couple->setup_code_hash
                && $couple->setup_code_expires_at?->isFuture()
                && hash_equals($couple->setup_code_hash, $hash);
            if (! $couple || (! $isRecovery && (! $isSetup || $replace))) {
                throw ValidationException::withMessages(['code' => ['The private code is invalid or expired.']]);
            }

            $partnerDevices = $couple->devices()->where('partner_type', $input['partner_type']);
            if ($isSetup && ! $isRecovery && (clone $partnerDevices)->exists()) {
                throw ValidationException::withMessages(['code' => ['Use your recovery code to restore an existing partner.']]);
            }
            if (! $replace && (clone $partnerDevices)->whereNull('revoked_at')->exists()) {
                throw ValidationException::withMessages(['partner_type' => ['This partner already has an active phone. Choose Replace phone.']]);
            }
            if ($replace) {
                (clone $partnerDevices)->whereNull('revoked_at')->update(['revoked_at' => now(), 'is_primary' => false, 'push_token' => null, 'push_token_hash' => null, 'notification_mode' => 'off']);
            }

            if (! $replace) {
                foreach (['partner_one_name', 'partner_two_name'] as $field) {
                    $nickname = trim($input[$field] ?? '');
                    if ($nickname !== '') {
                        $couple->{$field} = $nickname;
                    }
                }
                if ($couple->isDirty()) {
                    $couple->save();
                }
            }

            $token = 'os_'.rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $device = new Device(['device_name' => $input['device_name'], 'device_identifier' => $input['device_identifier']]);
            $device->couple_id = $couple->id;
            $device->partner_type = $input['partner_type'];
            $device->token_hash = hash('sha256', $token);
            $device->is_primary = true;
            $device->registered_at = now();
            $device->last_seen_at = now();
            $device->save();
            ActivityLog::record($device, $replace ? 'phone_replaced' : 'phone_connected');

            if ($couple->devices()->distinct()->count('partner_type') === 2) {
                $couple->setup_code_hash = null;
                $couple->setup_code_expires_at = null;
                $couple->save();
            }

            return ['device' => $device, 'couple' => $couple, 'token' => $token];
        }, 3);
    }

    public function createSpace(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $setup = 'setup-'.bin2hex(random_bytes(32));
            $recovery = 'recovery-'.bin2hex(random_bytes(32));
            $couple = new CoupleProfile([
                'partner_one_name' => trim($input['partner_one_name'] ?? '') ?: 'First partner',
                'partner_two_name' => trim($input['partner_two_name'] ?? '') ?: 'Second partner',
            ]);
            $couple->setup_code_hash = hash('sha256', $setup);
            $couple->setup_code_expires_at = now()->addHours(24);
            $couple->recovery_code_hash = hash('sha256', $recovery);
            $couple->save();

            return ['setup_code' => $setup, 'expires_at' => $couple->setup_code_expires_at->toIso8601String(), 'recovery_code' => $recovery];
        });
    }

    public function revoke(Device $actor, ?int $deviceId): Device
    {
        return DB::transaction(function () use ($actor, $deviceId): Device {
            CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
            $this->assertActive($actor);
            $target = Device::query()->where('couple_id', $actor->couple_id)->findOrFail($deviceId ?? $actor->id);
            if (! $target->revoked_at) {
                $target->revoked_at = now();
                $target->push_token = null;
                $target->push_token_hash = null;
                $target->notification_mode = 'off';
                $target->is_primary = false;
                $target->save();
                ActivityLog::record($actor, 'phone_revoked');
            }

            return $target;
        }, 3);
    }

    public function updateProfile(Device $actor, array $input): CoupleProfile
    {
        return DB::transaction(function () use ($actor, $input): CoupleProfile {
            $couple = CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
            $this->assertActive($actor);
            $couple->fill($input);
            if ($couple->isDirty()) {
                $couple->save();
                ActivityLog::record($actor, 'profile_updated');
            }

            return $couple;
        }, 3);
    }

    public function rotateRecoveryCode(): string
    {
        return DB::transaction(function (): string {
            $couple = CoupleProfile::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $code = 'recovery-'.bin2hex(random_bytes(32));
            $couple->recovery_code_hash = hash('sha256', $code);
            $couple->save();

            return $code;
        }, 3);
    }

    private function assertActive(Device $actor): void
    {
        abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
    }
}
