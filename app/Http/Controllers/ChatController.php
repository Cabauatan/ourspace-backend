<?php

namespace App\Http\Controllers;

use App\Events\ChatChanged;
use App\Http\Requests\SendMessageRequest;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ChatController extends Controller
{
    private function result(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Your shared chat.', 'data' => $data], $status);
    }

    public function show(Request $request, ChatService $service): JsonResponse
    {
        return $this->result(['id' => $service->conversation($request->user())->id, 'channel' => ChatChanged::channel($request->user()), 'partner_typing' => $service->partnerTyping($request->user())]);
    }

    public function authorizeChannel(Request $request): JsonResponse
    {
        $data = $request->validate(['socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/', 'max:100'], 'channel_name' => ['required', 'string', 'max:150'], 'callback' => ['prohibited']]);
        abort_unless($data['channel_name'] === 'private-'.ChatChanged::channel($request->user()), 403);

        return response()->json(Broadcast::connection('reverb')->validAuthenticationResponse($request, true));
    }

    public function index(Request $request, ChatService $service): JsonResponse
    {
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1', 'prohibits:after,ids'], 'after' => ['nullable', 'integer', 'min:1', 'prohibits:before,ids'], 'ids' => ['sometimes', 'array', 'min:1', 'max:50'], 'ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $query = $service->query($request->user());
        if (isset($data['ids'])) {
            return $this->result(['items' => $service->data($query->whereIn('id', $data['ids'])->orderBy('id')->get(), $request)]);
        }
        if (isset($data['before'])) {
            $service->message($request->user(), $data['before']);
            $query->where('id', '<', $data['before']);
        }
        if (isset($data['after'])) {
            $service->message($request->user(), $data['after']);
            $messages = $query->where('id', '>', $data['after'])->orderBy('id')->limit(31)->get();
            $more = $messages->count() > 30;
            $messages = $messages->take(30);

            return $this->result(['items' => $service->data($messages, $request), 'has_more' => $more, 'next_after' => $messages->last()?->id ?? $data['after']]);
        }
        $messages = $query->orderByDesc('id')->limit(31)->get();
        $more = $messages->count() > 30;
        $messages = $messages->take(30)->reverse()->values();

        return $this->result(['items' => $service->data($messages, $request), 'has_more' => $more, 'next_before' => $messages->first()?->id]);
    }

    public function message(Request $request, int $message, ChatService $service): JsonResponse
    {
        return $this->result($service->data(collect([$service->message($request->user(), $message)]), $request)[0]);
    }

    public function store(SendMessageRequest $request, ChatService $service): JsonResponse
    {
        return $this->result($service->data(collect([$service->send($request->user(), $request->validated())]), $request)[0], 201);
    }

    public function destroy(Request $request, int $message, ChatService $service): JsonResponse
    {
        return $this->result($service->data(collect([$service->remove($request->user(), $message)]), $request)[0]);
    }

    public function reaction(Request $request, int $message, ChatService $service): JsonResponse
    {
        $data = $request->validate(['emoji' => ['present', 'nullable', Rule::in(['❤️', '😂', '🥰', '😮', '😢', '👍'])], 'partner_type' => ['prohibited']]);

        return $this->result($service->data(collect([$service->react($request->user(), $message, $data['emoji'])]), $request)[0]);
    }

    public function read(Request $request, ChatService $service): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:50'], 'ids.*' => ['required', 'integer', 'min:1', 'distinct'], 'partner_type' => ['prohibited'], 'read_at' => ['prohibited']]);
        $service->read($request->user(), $data['ids']);

        return $this->result(null);
    }

    public function typing(Request $request, ChatService $service): JsonResponse
    {
        $data = $request->validate(['typing' => ['required', 'boolean']]);
        $service->typing($request->user(), $data['typing']);

        return $this->result(null);
    }

    public function media(Request $request, int $message, int $media, ChatService $service): BinaryFileResponse
    {
        $item = $service->message($request->user(), $message);
        abort_if($item->deleted_at, 404);
        $file = DB::table('message_media')->where('message_id', $item->id)->where('id', $media)->first();
        abort_unless($file && Storage::disk('local')->exists($file->file_path), 404);

        return response()->file(Storage::disk('local')->path($file->file_path), ['Content-Type' => $file->mime_type, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
