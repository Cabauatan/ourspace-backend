<?php

namespace App\Services;

use App\Events\ChallengesChanged;
use App\Models\Achievement;
use App\Models\ActivityLog;
use App\Models\Challenge;
use App\Models\CoupleChallenge;
use App\Models\CoupleProfile;
use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ChallengeService
{
    private function lockActor(Device $actor): void
    {
        CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
        abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
    }

    private function changed(Device $actor): void
    {
        DB::afterCommit(function () use ($actor): void {
            try {
                event(new ChallengesChanged($actor->couple_id));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function start(Device $actor, int $challengeId): CoupleChallenge
    {
        return DB::transaction(function () use ($actor, $challengeId): CoupleChallenge {
            $this->lockActor($actor);
            $existing = CoupleChallenge::query()->where('couple_id', $actor->couple_id)->where('challenge_id', $challengeId)->first();
            if ($existing) {
                return $existing;
            }
            $challenge = Challenge::query()->where('active', true)->findOrFail($challengeId);
            $enrollment = CoupleChallenge::query()->create(['couple_id' => $actor->couple_id, 'challenge_id' => $challengeId, 'title' => $challenge->title, 'description' => $challenge->description, 'category' => $challenge->category, 'difficulty' => $challenge->difficulty, 'points' => $challenge->points, 'status' => 'in_progress']);
            ActivityLog::record($actor, 'challenge_started');
            $this->changed($actor);

            return $enrollment;
        });
    }

    public function find(Device $actor, int $id): CoupleChallenge
    {
        return CoupleChallenge::query()->where('couple_id', $actor->couple_id)->findOrFail($id);
    }

    public function status(Device $actor, int $id, string $status): CoupleChallenge
    {
        return DB::transaction(function () use ($actor, $id, $status): CoupleChallenge {
            $this->lockActor($actor);
            $enrollment = $this->find($actor, $id);
            abort_if($enrollment->status === 'completed', 409);
            if ($enrollment->status !== $status) {
                $enrollment->update(['status' => $status]);
                ActivityLog::record($actor, $status === 'cancelled' ? 'challenge_cancelled' : 'challenge_resumed');
                $this->changed($actor);
            }

            return $enrollment;
        });
    }

    public function complete(Device $actor, int $id, array $input): CoupleChallenge
    {
        $path = null;
        $photo = $input['proof_photo'] ?? null;
        try {
            return DB::transaction(function () use ($actor, $id, $photo, &$path): CoupleChallenge {
                $this->lockActor($actor);
                $enrollment = $this->find($actor, $id);
                if ($enrollment->status === 'completed') {
                    abort_if($photo && $enrollment->proof_hash !== hash_file('sha256', $photo->getRealPath()), 409);

                    return $enrollment;
                }
                abort_unless($enrollment->status === 'in_progress', 409);
                if ($photo) {
                    $path = $photo->store('couples/'.$actor->couple_id.'/challenges', 'local');
                    if (! $path) {
                        throw new \RuntimeException('Unable to save challenge proof.');
                    }
                    $enrollment->proof_photo = $path;
                    $enrollment->proof_mime = $photo->getMimeType();
                    $enrollment->proof_size = $photo->getSize();
                    $enrollment->proof_hash = hash_file('sha256', $photo->getRealPath());
                }
                $enrollment->status = 'completed';
                $enrollment->completed_at = now();
                $enrollment->completed_by_partner = $actor->partner_type;
                $enrollment->save();
                $metrics = $this->metrics($actor->couple_id);
                foreach (Achievement::query()->get() as $achievement) {
                    if (($metrics[$achievement->metric] ?? 0) >= $achievement->threshold) {
                        DB::table('couple_achievements')->insertOrIgnore(['couple_id' => $actor->couple_id, 'achievement_id' => $achievement->id, 'unlocked_at' => now()]);
                    }
                }
                ActivityLog::record($actor, 'challenge_completed');
                app(NotificationService::class)->other($actor, 'challenge_completed', (string) $enrollment->id);
                $this->changed($actor);

                return $enrollment;
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    private function metrics(int $coupleId): array
    {
        $row = CoupleChallenge::query()->where('couple_id', $coupleId)->where('status', 'completed')->selectRaw('COUNT(*) AS completed_count, COALESCE(SUM(points), 0) AS total_points, COUNT(DISTINCT category) AS category_count')->first();

        return ['completed_count' => (int) $row->completed_count, 'total_points' => (int) $row->total_points, 'category_count' => (int) $row->category_count];
    }

    public function progress(int $coupleId): array
    {
        $metrics = $this->metrics($coupleId);
        $levels = config('challenges.levels');
        $current = $levels[0];
        $next = null;
        foreach ($levels as $level) {
            if ($metrics['total_points'] >= $level['points']) {
                $current = $level;
            } else {
                $next = $level;
                break;
            }
        }
        $unlocks = DB::table('couple_achievements')->where('couple_id', $coupleId)->get()->keyBy('achievement_id');
        $achievements = Achievement::query()->orderBy('id')->get()->map(fn (Achievement $achievement) => [
            'id' => $achievement->id, 'title' => $achievement->title, 'description' => $achievement->description, 'badge' => $achievement->badge,
            'unlocked_at' => isset($unlocks[$achievement->id]) ? CarbonImmutable::parse($unlocks[$achievement->id]->unlocked_at)->toIso8601String() : null,
            'progress_current' => min($metrics[$achievement->metric] ?? 0, $achievement->threshold), 'progress_target' => $achievement->threshold,
        ])->all();

        return $metrics + ['level' => $current, 'next_level' => $next, 'points_to_next' => $next ? max(0, $next['points'] - $metrics['total_points']) : 0,
            'progress_percent' => $next ? (int) floor(100 * ($metrics['total_points'] - $current['points']) / max(1, $next['points'] - $current['points'])) : 100,
            'achievements' => $achievements];
    }
}
