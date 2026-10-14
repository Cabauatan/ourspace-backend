<?php

namespace Tests\Feature;

use App\Events\ChatChanged;
use App\Models\Conversation;
use App\Models\CoupleProfile;
use App\Models\Device;
use App\Models\Message;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use DatabaseMigrations;

    private function token(string $partner = 'partner_one'): string
    {
        CoupleProfile::query()->first() ?? CoupleProfile::factory()->create(['id' => 1]);

        return $this->postJson('/api/device/register', ['code' => 'test-recovery-code', 'partner_type' => $partner, 'device_name' => 'Chat test', 'device_identifier' => (string) Str::uuid()])->assertCreated()->json('data.token');
    }

    private function send(string $body = 'Hello', array $extra = []): array
    {
        return $this->postJson('/api/chat/messages', $extra + ['client_id' => (string) Str::uuid(), 'body' => $body])->assertCreated()->json('data');
    }

    public function test_shared_conversation_replies_retries_and_cursor_pagination(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($one);
        $chat = $this->getJson('/api/chat')->assertOk()->json('data');
        $payload = ['client_id' => (string) Str::uuid(), 'body' => ' Hello '];
        $first = $this->postJson('/api/chat/messages', $payload)->assertCreated()->assertJsonPath('data.body', 'Hello')->json('data');
        $this->postJson('/api/chat/messages', $payload)->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->postJson('/api/chat/messages', array_replace($payload, ['body' => 'Changed']))->assertConflict();
        $this->withToken($two)->getJson('/api/chat')->assertOk()->assertJsonPath('data.id', $chat['id']);
        $reply = $this->send('Hi', ['reply_to_id' => $first['id']]);
        $this->assertSame('Hello', $reply['reply']['body']);
        $conversation = Conversation::firstOrFail();
        Message::factory()->count(31)->create(['conversation_id' => $conversation->id]);
        $latest = $this->getJson('/api/chat/messages')->assertOk()->assertJsonCount(30, 'data.items')->assertJsonPath('data.has_more', true)->json('data');
        $older = $this->getJson('/api/chat/messages?before='.$latest['next_before'])->assertOk()->assertJsonCount(3, 'data.items')->assertJsonPath('data.has_more', false)->json('data');
        $this->assertSame($first['id'], $older['items'][0]['id']);
        $this->assertLessThan($latest['items'][0]['id'], end($older['items'])['id']);
        $this->assertDatabaseCount('conversations', 1);
        $newer = $this->getJson('/api/chat/messages?after='.$first['id'])->assertOk()->assertJsonCount(30, 'data.items')->assertJsonPath('data.has_more', true)->json('data');
        $this->assertSame($reply['id'], $newer['items'][0]['id']);
        $this->getJson('/api/chat/messages?after='.$newer['next_after'])->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.has_more', false);
        $this->getJson('/api/chat/messages?after='.$first['id'].'&before='.$reply['id'])->assertUnprocessable();
    }

    public function test_private_routes_and_tenant_scoping_block_foreign_messages_and_channels(): void
    {
        $this->getJson('/api/chat')->assertUnauthorized();
        $token = $this->token();
        $foreign = Message::factory()->create();
        $this->withToken($token);
        foreach (['GET', 'DELETE', 'PUT'] as $method) {
            $path = '/api/chat/messages/'.$foreign->id.($method === 'PUT' ? '/reaction' : '');
            $this->json($method, $path, ['emoji' => '❤️'])->assertNotFound();
        }
        $this->getJson('/api/chat/messages?before='.$foreign->id)->assertNotFound();
        $this->getJson('/api/chat/messages?after='.$foreign->id)->assertNotFound();
        $this->postJson('/api/chat/read', ['ids' => [$foreign->id]])->assertNotFound();
        $this->postJson('/api/chat/messages', ['client_id' => (string) Str::uuid(), 'body' => 'Reply', 'reply_to_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('reply_to_id');
        $this->getJson('/api/chat/messages?ids[]='.$foreign->id)->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_only_own_messages_can_be_deleted_and_replies_become_tombstones(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($one);
        $first = $this->send('SECRET_BODY');
        $this->withToken($two)->deleteJson('/api/chat/messages/'.$first['id'])->assertForbidden();
        $reply = $this->send('Reply', ['reply_to_id' => $first['id']]);
        $this->withToken($one)->deleteJson('/api/chat/messages/'.$first['id'])->assertOk()->assertJsonPath('data.body', null);
        $this->withToken($two)->getJson('/api/chat/messages/'.$reply['id'])->assertOk()->assertJsonPath('data.reply.deleted', true)->assertJsonPath('data.reply.body', null);
        $this->getJson('/api/chat/messages')->assertOk()->assertDontSee('SECRET_BODY');
        $this->putJson('/api/chat/messages/'.$first['id'].'/reaction', ['emoji' => '❤️'])->assertConflict();
        $this->postJson('/api/chat/messages', ['client_id' => (string) Str::uuid(), 'body' => 'Again', 'reply_to_id' => $first['id']])->assertUnprocessable();
        $this->withToken($one)->deleteJson('/api/chat/messages/'.$first['id'])->assertOk();
    }

    public function test_reactions_and_reads_derive_the_permanent_partner_and_are_idempotent(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($one);
        $message = $this->send();
        $id = $message['id'];
        $this->postJson('/api/chat/read', ['ids' => [$id]])->assertOk();
        $this->assertDatabaseCount('message_reads', 0);
        $this->withToken($two);
        $this->putJson('/api/chat/messages/'.$id.'/reaction', ['emoji' => '❤️'])->assertOk();
        $this->putJson('/api/chat/messages/'.$id.'/reaction', ['emoji' => '🥰'])->assertOk()->assertJsonCount(1, 'data.reactions')->assertJsonPath('data.reactions.0.partner_type', 'partner_two');
        $this->assertDatabaseCount('message_reactions', 1);
        $this->putJson('/api/chat/messages/'.$id.'/reaction', ['emoji' => 'evil'])->assertUnprocessable();
        $this->putJson('/api/chat/messages/'.$id.'/reaction', ['emoji' => '❤️', 'partner_type' => 'partner_one'])->assertUnprocessable();
        $this->postJson('/api/chat/read', ['ids' => [$id], 'read_at' => '2000-01-01'])->assertUnprocessable();
        $this->postJson('/api/chat/read', ['ids' => [$id]])->assertOk();
        $read = DB::table('message_reads')->first()->read_at;
        $this->travel(1)->minutes();
        $this->postJson('/api/chat/read', ['ids' => [$id]])->assertOk();
        $this->assertSame($read, DB::table('message_reads')->first()->read_at);
        $this->assertDatabaseCount('message_reads', 1);
        $this->withToken($one)->getJson('/api/chat/messages/'.$id)->assertOk()->assertJsonPath('data.reads.0.partner_type', 'partner_two');
        $this->withToken($two)->putJson('/api/chat/messages/'.$id.'/reaction', ['emoji' => null])->assertOk()->assertJsonCount(0, 'data.reactions');
    }

    public function test_read_batch_is_atomic_and_request_payloads_are_validated(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($one);
        $message = $this->send();
        $foreign = Message::factory()->create();
        $this->withToken($two)->postJson('/api/chat/read', ['ids' => [$message['id'], $foreign->id]])->assertNotFound();
        $this->assertDatabaseCount('message_reads', 0);
        foreach ([['body' => '   '], ['body' => str_repeat('a', 4001)], ['body' => 'Hello', 'partner_type' => 'partner_one']] as $payload) {
            $this->postJson('/api/chat/messages', $payload + ['client_id' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->getJson('/api/chat/messages?ids[]=1&ids[]=1')->assertUnprocessable();
        $this->postJson('/api/chat/read', ['ids' => []])->assertUnprocessable();
    }

    public function test_photos_and_videos_are_private_and_deletion_removes_files(): void
    {
        Storage::fake('local');
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($one);
        $image = UploadedFile::fake()->image('photo.jpg');
        $message = $this->post('/api/chat/messages', ['client_id' => (string) Str::uuid(), 'attachment' => $image], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.media.0.media_type', 'image')->json('data');
        $file = DB::table('message_media')->first();
        Storage::disk('local')->assertExists($file->file_path);
        $url = '/api/chat/messages/'.$message['id'].'/media/'.$file->id;
        $this->withToken($two)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->withToken('')->getJson($url)->assertUnauthorized();
        $foreign = Message::factory()->create();
        $this->withToken($one)->getJson('/api/chat/messages/'.$foreign->id.'/media/'.$file->id)->assertNotFound();
        $this->deleteJson('/api/chat/messages/'.$message['id'])->assertOk();
        Storage::disk('local')->assertMissing($file->file_path);
        $this->getJson($url)->assertNotFound();
        $video = new UploadedFile(base_path('../docs/phase-4-demo-video.mp4'), 'clip.mp4', null, null, true);
        $clip = $this->post('/api/chat/messages', ['client_id' => (string) Str::uuid(), 'attachment' => $video], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.media.0.media_type', 'video')->json('data');
        $this->withToken($two)->get('/api/chat/messages/'.$clip['id'].'/media/'.$clip['media'][0]['id'], ['Range' => 'bytes=0-7'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-7/'.filesize(base_path('../docs/phase-4-demo-video.mp4')));
        $this->post('/api/chat/messages', ['client_id' => (string) Str::uuid(), 'attachment' => UploadedFile::fake()->create('payload.html', 1, 'text/html')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/chat/messages', ['client_id' => (string) Str::uuid(), 'attachment' => UploadedFile::fake()->image('huge.png')->size(5121)], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_channel_signatures_are_limited_to_current_device_and_revocation_removes_broadcast_targets(): void
    {
        config(['broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($one);
        $device = Device::where('token_hash', hash('sha256', $one))->firstOrFail();
        $other = Device::where('token_hash', hash('sha256', $two))->firstOrFail();
        $channel = 'private-'.ChatChanged::channel($device);
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertOk()->assertJsonPath('auth', 'test-key:'.hash_hmac('sha256', '123.456:'.$channel, 'test-secret'));
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-'.ChatChanged::channel($other)])->assertForbidden();
        $this->postJson('/api/broadcasting/auth', ['socket_id' => 'bad', 'channel_name' => $channel])->assertUnprocessable();
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel, 'callback' => 'alert'])->assertUnprocessable();
        $event = new ChatChanged($device->couple_id, 'message', 77);
        $this->assertCount(2, $event->broadcastOn());
        $this->assertSame(['kind' => 'message', 'message_id' => 77], $event->broadcastWith());
        $this->postJson('/api/device/revoke')->assertOk();
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertUnauthorized();
        $this->getJson('/api/chat/messages')->assertUnauthorized();
        $this->assertCount(1, $event->broadcastOn());
        $this->assertSame('private-'.ChatChanged::channel($other), $event->broadcastOn()[0]->name);
    }

    public function test_typing_is_shared_but_expires_and_is_never_a_client_supplied_identity(): void
    {
        $one = $this->token();
        $two = $this->token('partner_two');
        $this->withToken($one)->postJson('/api/chat/typing', ['typing' => true])->assertOk();
        $this->withToken($two)->getJson('/api/chat')->assertOk()->assertJsonPath('data.partner_typing', true);
        $this->travel(7)->seconds();
        $this->getJson('/api/chat')->assertOk()->assertJsonPath('data.partner_typing', false);
        $this->withToken($one)->postJson('/api/chat/typing', ['typing' => false])->assertOk();
        $this->postJson('/api/chat/typing', ['typing' => 'spoof'])->assertUnprocessable();
    }
}
