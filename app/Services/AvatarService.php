<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CoupleProfile;
use App\Models\Device;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AvatarService
{
    public function update(Device $actor, string $partner, ?UploadedFile $file): CoupleProfile
    {
        $newPath = null;
        $oldPath = null;
        try {
            $couple = DB::transaction(function () use ($actor, $partner, $file, &$newPath, &$oldPath): CoupleProfile {
                $couple = CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
                abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
                $field = $partner.'_avatar';
                $oldPath = $couple->{$field};
                if ($file) {
                    $newPath = $file->store('avatars/'.$couple->id, 'local');
                    if (! $newPath) {
                        throw new RuntimeException('Unable to store the avatar.');
                    }
                }
                if ($newPath !== $oldPath) {
                    $couple->{$field} = $newPath;
                    $couple->save();
                    ActivityLog::record($actor, $file ? 'avatar_updated' : 'avatar_removed');
                }

                return $couple;
            });
        } catch (Throwable $error) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $error;
        }
        if ($oldPath && $oldPath !== $newPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return $couple;
    }
}
