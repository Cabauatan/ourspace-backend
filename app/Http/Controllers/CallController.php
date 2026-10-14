<?php

namespace App\Http\Controllers;

use App\Models\CoupleProfile;
use App\Models\Device;
use App\Models\PartnerCall;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CallController extends Controller
{
    private function result(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Your private partner calls.', 'data' => $data], $status);
    }

    private function locked(Request $request, callable $action): JsonResponse
    {
        return DB::transaction(function () use ($request, $action): JsonResponse {
            $actor = $request->user();
            CoupleProfile::query()->whereKey($actor->couple_id)->lockForUpdate()->firstOrFail();
            abort_unless(Device::query()->whereKey($actor->id)->whereNull('revoked_at')->exists(), 401);
            PartnerCall::query()->where('couple_id', $actor->couple_id)->whereIn('status', ['ringing', 'active'])->get()->each(fn (PartnerCall $call) => $this->expire($call));

            return $action($actor);
        }, 3);
    }

    private function expire(PartnerCall $call): void
    {
        $call->expireIfStale();
    }

    private function finish(PartnerCall $call, string $status): void
    {
        $call->forceFill(['status' => $status, 'ended_at' => now()])->save();
        DB::table('call_signals')->where('call_id', $call->id)->delete();
    }

    private function call(Device $actor, string $id): PartnerCall
    {
        $call = PartnerCall::query()->where('couple_id', $actor->couple_id)->findOrFail($id);
        abort_unless(in_array($actor->id, [$call->caller_device_id, $call->callee_device_id], true), 403);

        return $call;
    }

    private function data(PartnerCall $call): array
    {
        return $call->only(['id', 'kind', 'status', 'caller_device_id', 'callee_device_id', 'caller_partner', 'created_at', 'answered_at', 'ended_at']);
    }

    public function current(Request $request): JsonResponse
    {
        return $this->locked($request, function (Device $actor): JsonResponse {
            $call = PartnerCall::query()->where('couple_id', $actor->couple_id)->whereIn('status', ['ringing', 'active'])->where(fn ($query) => $query->where('caller_device_id', $actor->id)->orWhere('callee_device_id', $actor->id))->first();

            return $this->result($call ? $this->data($call) : null);
        });
    }

    public function history(Request $request): JsonResponse
    {
        return $this->locked($request, function (Device $actor): JsonResponse {
            $page = PartnerCall::query()->where('couple_id', $actor->couple_id)->latest()->paginate(20);

            return $this->result(['items' => $page->getCollection()->map(fn (PartnerCall $call) => $this->data($call)), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null]);
        });
    }

    public function config(Request $request): JsonResponse
    {
        $servers = [];
        if (config('calls.stun_urls')) {
            $servers[] = ['urls' => config('calls.stun_urls')];
        }
        $relay = (bool) (config('calls.turn_urls') && config('calls.turn_secret'));
        if ($relay) {
            $username = now()->addHour()->timestamp.':'.$request->user()->id;
            $servers[] = ['urls' => config('calls.turn_urls'), 'username' => $username, 'credential' => base64_encode(hash_hmac('sha1', $username, config('calls.turn_secret'), true))];
        }
        abort_if(config('calls.relay_only') && ! $relay, 503, 'Calling is temporarily unavailable.');

        return $this->result(['iceServers' => $servers, 'iceTransportPolicy' => config('calls.relay_only') ? 'relay' : 'all', 'relay_available' => $relay]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['kind' => ['required', Rule::in(['voice', 'video'])], 'client_id' => ['required', 'uuid'], 'couple_id' => ['prohibited'], 'callee_device_id' => ['prohibited'], 'caller_device_id' => ['prohibited']]);

        return $this->locked($request, function (Device $actor) use ($input): JsonResponse {
            $existing = PartnerCall::query()->where('caller_device_id', $actor->id)->where('client_id', $input['client_id'])->first();
            if ($existing) {
                return $this->result($this->data($existing));
            }
            abort_if(PartnerCall::query()->where('couple_id', $actor->couple_id)->whereIn('status', ['ringing', 'active'])->exists(), 409, 'There is already a call in progress.');
            $callee = Device::query()->where('couple_id', $actor->couple_id)->where('partner_type', '!=', $actor->partner_type)->whereNull('revoked_at')->first();
            abort_unless($callee, 409, 'Your partner needs to connect a phone first.');
            $call = PartnerCall::query()->create(['id' => (string) Str::uuid(), 'client_id' => $input['client_id'], 'couple_id' => $actor->couple_id, 'caller_device_id' => $actor->id, 'callee_device_id' => $callee->id, 'caller_partner' => $actor->partner_type, 'kind' => $input['kind'], 'status' => 'ringing', 'caller_seen_at' => now()]);
            app(NotificationService::class)->other($actor, 'incoming_call', $call->id);

            return $this->result($this->data($call), 201);
        });
    }

    public function action(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['action' => ['required', Rule::in(['accept', 'decline', 'end', 'heartbeat'])]]);

        return $this->locked($request, function (Device $actor) use ($id, $input): JsonResponse {
            $call = $this->call($actor, $id);
            $action = $input['action'];
            if ($action === 'end' && ! in_array($call->status, ['ringing', 'active'], true)) {
                return $this->result($this->data($call));
            }
            abort_unless(in_array($call->status, ['ringing', 'active'], true), 409, 'This call has ended.');
            if ($action === 'accept' || $action === 'decline') {
                abort_unless($actor->id === $call->callee_device_id, 403);
                if ($action === 'accept' && $call->status === 'active') {
                    return $this->result($this->data($call));
                }
                abort_unless($call->status === 'ringing', 409);
                if ($action === 'accept') {
                    $call->forceFill(['status' => 'active', 'answered_at' => now(), 'callee_seen_at' => now(), 'caller_seen_at' => now()])->save();
                } else {
                    $this->finish($call, 'declined');
                }
            } elseif ($action === 'end') {
                $this->finish($call, 'ended');
            } else {
                $call->forceFill([$actor->id === $call->caller_device_id ? 'caller_seen_at' : 'callee_seen_at' => now()])->save();
            }

            return $this->result($this->data($call));
        });
    }

    public function signal(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['type' => ['required', Rule::in(['offer', 'answer', 'ice'])], 'sdp' => ['required_unless:type,ice', 'string', 'max:60000'], 'candidate' => ['required_if:type,ice', 'array:candidate,sdpMid,sdpMLineIndex,usernameFragment'], 'candidate.candidate' => ['required_if:type,ice', 'string', 'max:2048'], 'candidate.sdpMid' => ['nullable', 'string', 'max:64'], 'candidate.sdpMLineIndex' => ['nullable', 'integer', 'min:0', 'max:3'], 'candidate.usernameFragment' => ['nullable', 'string', 'max:256']]);

        return $this->locked($request, function (Device $actor) use ($id, $input): JsonResponse {
            $call = $this->call($actor, $id);
            abort_unless(in_array($call->status, ['ringing', 'active'], true), 409);
            if ($input['type'] !== 'ice') {
                abort_unless($input['type'] === 'offer' ? $actor->id === $call->caller_device_id : ($actor->id === $call->callee_device_id && $call->status === 'active'), 403);
                abort_if(DB::table('call_signals')->where('call_id', $id)->where('type', $input['type'])->exists(), 409);
            }
            abort_if(DB::table('call_signals')->where('call_id', $id)->count() >= 256, 429);
            $payload = $input['type'] === 'ice' ? $input['candidate'] : ['sdp' => $input['sdp']];
            DB::table('call_signals')->insert(['call_id' => $id, 'device_id' => $actor->id, 'type' => $input['type'], 'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'created_at' => now()]);

            return $this->result(null, 201);
        });
    }

    public function signals(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['after' => ['sometimes', 'integer', 'min:0']]);

        return $this->locked($request, function (Device $actor) use ($id, $input): JsonResponse {
            $call = $this->call($actor, $id);
            $signals = DB::table('call_signals')->where('call_id', $id)->where('device_id', '!=', $actor->id)->where('id', '>', $input['after'] ?? 0)->orderBy('id')->limit(100)->get();

            return $this->result(['call' => $this->data($call), 'items' => $signals->map(fn ($signal): array => ['id' => $signal->id, 'type' => $signal->type, 'data' => json_decode(Crypt::decryptString($signal->payload), true, 512, JSON_THROW_ON_ERROR)]), 'next_after' => $signals->last()?->id ?? ($input['after'] ?? 0)]);
        });
    }
}
