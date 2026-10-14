<?php

namespace App\Services;

use App\Events\ChatChanged;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\CoupleProfile;
use App\Models\Device;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChatService
{
    public function conversation(Device $device): Conversation
    {
        return DB::transaction(function () use ($device): Conversation {
            CoupleProfile::query()->whereKey($device->couple_id)->lockForUpdate()->firstOrFail();

            return Conversation::query()->firstOrCreate(['couple_id' => $device->couple_id]);
        });
    }

    public function query(Device $device): Builder
    {
        return Message::query()->where('conversation_id', $this->conversation($device)->id)->with('reply');
    }

    public function message(Device $device, int $id): Message
    {
        return $this->query($device)->findOrFail($id);
    }

    public function data(Collection $messages, Request $request): array
    {
        $ids = $messages->pluck('id');
        foreach (['chatMedia' => 'message_media', 'chatReactions' => 'message_reactions', 'chatReads' => 'message_reads'] as $relation => $table) {
            $rows = DB::table($table)->whereIn('message_id', $ids)->get()->groupBy('message_id');
            foreach ($messages as $message) {
                $message->setRelation($relation, $rows->get($message->id, collect()));
            }
        }

        return MessageResource::collection($messages)->resolve($request);
    }

    public function changed(Device $device, string $kind, ?int $id = null): void
    {
        DB::afterCommit(function () use ($device, $kind, $id): void {
            try {
                event(new ChatChanged($device->couple_id, $kind, $id));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function send(Device $device, array $data): Message
    {
        $path = null;
        $file = $data['attachment'] ?? null;
        $body = trim($data['body'] ?? '');
        if ($body === '' && ! $file) {
            throw ValidationException::withMessages(['body' => 'Write a message or attach a photo/video.']);
        }
        $fingerprint = hash('sha256', json_encode([$body, $data['reply_to_id'] ?? null, $file ? hash_file('sha256', $file->getRealPath()) : null]));
        try {
            $message = DB::transaction(function () use ($device, $data, $file, $body, $fingerprint, &$path): Message {
                $conversation = $this->conversation($device);
                $existing = Message::query()->where('conversation_id', $conversation->id)->where('partner_type', $device->partner_type)->where('client_id', $data['client_id'])->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->payload_hash, $fingerprint), 409);

                    return $existing->load('reply');
                }
                if (! empty($data['reply_to_id'])) {
                    $reply = Message::query()->where('conversation_id', $conversation->id)->whereNull('deleted_at')->find($data['reply_to_id']);
                    if (! $reply) {
                        throw ValidationException::withMessages(['reply_to_id' => 'This message is no longer available to reply to.']);
                    }
                }
                $message = Message::query()->create(['conversation_id' => $conversation->id, 'partner_type' => $device->partner_type, 'client_id' => $data['client_id'], 'payload_hash' => $fingerprint, 'body' => $body ?: null, 'reply_to_id' => $data['reply_to_id'] ?? null]);
                if ($file) {
                    $path = $file->store('couples/'.$device->couple_id.'/chat', 'local');
                    if (! $path) {
                        throw new \RuntimeException('Unable to save the attachment.');
                    }
                    DB::table('message_media')->insert(['message_id' => $message->id, 'file_path' => $path, 'media_type' => str_starts_with($file->getMimeType(), 'video/') ? 'video' : 'image', 'mime_type' => $file->getMimeType(), 'file_size' => $file->getSize(), 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->changed($device, 'message', $message->id);
                app(NotificationService::class)->other($device, 'chat_message', (string) $message->id);

                return $message->load('reply');
            });

            return $message;
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            } throw $exception;
        }
    }

    public function remove(Device $device, int $id): Message
    {
        $paths = [];
        $message = DB::transaction(function () use ($device, $id, &$paths): Message {
            $this->conversation($device);
            $message = $this->query($device)->lockForUpdate()->findOrFail($id);
            abort_unless($message->partner_type === $device->partner_type, 403);
            if (! $message->deleted_at) {
                $paths = DB::table('message_media')->where('message_id', $id)->pluck('file_path')->all();
                DB::table('message_media')->where('message_id', $id)->delete();
                DB::table('message_reactions')->where('message_id', $id)->delete();
                $message->update(['body' => null, 'deleted_at' => now(), 'revision' => $message->revision + 1]);
                DB::afterCommit(fn () => Storage::disk('local')->delete($paths));
                $this->changed($device, 'deleted', $id);
            }

            return $message;
        });

        return $message;
    }

    public function react(Device $device, int $id, ?string $emoji): Message
    {
        return DB::transaction(function () use ($device, $id, $emoji): Message {
            $this->conversation($device);
            $message = $this->query($device)->lockForUpdate()->findOrFail($id);
            abort_if($message->deleted_at, 409);
            $query = DB::table('message_reactions')->where('message_id', $id)->where('partner_type', $device->partner_type);
            if ($emoji) {
                $query->updateOrInsert(['message_id' => $id, 'partner_type' => $device->partner_type], ['emoji' => $emoji, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $query->delete();
            }
            $message->increment('revision');
            $this->changed($device, 'reaction', $id);

            return $message;
        });
    }

    public function read(Device $device, array $ids): void
    {
        DB::transaction(function () use ($device, $ids): void {
            $this->conversation($device);
            $messages = $this->query($device)->whereIn('id', $ids)->lockForUpdate()->get();
            abort_unless($messages->count() === count($ids), 404);
            foreach ($messages as $message) {
                if ($message->partner_type === $device->partner_type || $message->deleted_at) {
                    continue;
                }
                $key = ['message_id' => $message->id, 'partner_type' => $device->partner_type];
                if (! DB::table('message_reads')->where($key)->exists()) {
                    DB::table('message_reads')->insert($key + ['read_at' => now()]);
                    $message->increment('revision');
                    $this->changed($device, 'read', $message->id);
                }
            }
        });
    }

    public function typing(Device $device, bool $typing): void
    {
        $key = 'chat-typing:'.$device->couple_id.':'.$device->partner_type;
        if ($typing) {
            Cache::put($key, true, now()->addSeconds(6));
        } else {
            Cache::forget($key);
        }
        $this->changed($device, 'typing');
    }

    public function partnerTyping(Device $device): bool
    {
        return (bool) Cache::get('chat-typing:'.$device->couple_id.':'.($device->partner_type === 'partner_one' ? 'partner_two' : 'partner_one'), false);
    }
}
