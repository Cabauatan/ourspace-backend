<?php

namespace App\Http\Controllers;

use App\Models\CoupleProfile;
use App\Models\Device;
use App\Notifications\SpaceNotification;
use App\Services\FirebasePushService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationService $service): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'unread' => ['nullable', 'boolean']]);
        $recipient = $service->recipient($request->user()->couple_id, $request->user()->partner_type);
        $query = $recipient->notifications();
        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return response()->json(['success' => true, 'data' => [
            'items' => collect($page->items())->map(fn ($notification) => ['id' => $notification->id, ...$notification->data, 'read_at' => $notification->read_at?->toIso8601String(), 'created_at' => $notification->created_at->toIso8601String()]),
            'unread_count' => $recipient->unreadNotifications()->count(),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]]);
    }

    public function read(Request $request, NotificationService $service, ?string $notification = null): JsonResponse
    {
        return DB::transaction(function () use ($request, $service, $notification): JsonResponse {
            $actor = $request->user();
            CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
            abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
            $recipient = $service->recipient($actor->couple_id, $actor->partner_type);
            if ($notification) {
                $recipient->notifications()->findOrFail($notification)->markAsRead();
            } else {
                $recipient->unreadNotifications()->update(['read_at' => now()]);
            }

            return response()->json(['success' => true, 'data' => ['unread_count' => $recipient->unreadNotifications()->count()]]);
        });
    }

    public function settings(Request $request, NotificationService $service, FirebasePushService $firebase): JsonResponse
    {
        $recipient = $service->recipient($request->user()->couple_id, $request->user()->partner_type);

        return response()->json(['success' => true, 'data' => ['enabled_types' => $recipient->enabled_types ?? array_keys(SpaceNotification::TYPES), 'types' => SpaceNotification::TYPES, 'mode' => $request->user()->notification_mode, 'firebase_ready' => $firebase->ready()]]);
    }

    public function saveSettings(Request $request, NotificationService $service, FirebasePushService $firebase): JsonResponse
    {
        $input = $request->validate(['enabled_types' => ['sometimes', 'array', 'max:'.count(SpaceNotification::TYPES)], 'enabled_types.*' => ['required', 'distinct', Rule::in(array_keys(SpaceNotification::TYPES))], 'mode' => ['sometimes', Rule::in(['off', 'local'])], 'couple_id' => ['prohibited'], 'partner_type' => ['prohibited'], 'device_id' => ['prohibited']]);
        DB::transaction(function () use ($request, $service, $input): void {
            $actor = $request->user();
            CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
            abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
            if (array_key_exists('enabled_types', $input)) {
                $service->recipient($actor->couple_id, $actor->partner_type)->update(['enabled_types' => $input['enabled_types']]);
            }
            if (isset($input['mode'])) {
                $actor->notification_mode = $input['mode'];
                $actor->push_token = null;
                $actor->push_token_hash = null;
                $actor->save();
            }
        });

        return $this->settings($request, $service, $firebase);
    }

    public function registerPush(Request $request, FirebasePushService $firebase): JsonResponse
    {
        $input = $request->validate(['token' => ['required', 'string', 'min:20', 'max:4096', 'regex:/^[A-Za-z0-9_:\-]+$/'], 'device_id' => ['prohibited'], 'couple_id' => ['prohibited'], 'partner_type' => ['prohibited']]);
        abort_unless($firebase->ready(), 503, 'Firebase is not configured on the server yet. Local reminders are available.');
        DB::transaction(function () use ($request, $input): void {
            $actor = $request->user();
            CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
            abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
            $hash = hash('sha256', $input['token']);
            $owner = Device::query()->where('push_token_hash', $hash)->whereKeyNot($actor->id)->first();
            if ($owner && ! $owner->revoked_at) {
                throw ValidationException::withMessages(['token' => 'This installation is already connected to another active phone.']);
            }
            if ($owner) {
                $owner->push_token = null;
                $owner->push_token_hash = null;
                $owner->save();
            }
            $actor->push_token = $input['token'];
            $actor->push_token_hash = $hash;
            $actor->notification_mode = 'push';
            $actor->save();
        });

        return response()->json(['success' => true, 'message' => 'Android push enabled.']);
    }

    public function reminders(Request $request, NotificationService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $service->localReminders($request->user())]);
    }
}
