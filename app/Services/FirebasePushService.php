<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FirebasePushService
{
    public function ready(): bool
    {
        return (bool) config('notifications.firebase_project_id') && is_file((string) config('notifications.firebase_credentials'));
    }

    public function validateConfiguration(): void
    {
        if (! $this->ready()) {
            throw new RuntimeException('Firebase project and credential file must be configured first.');
        }
        $response = Http::withToken($this->accessToken())->connectTimeout(3)->timeout(8)->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode(config('notifications.firebase_project_id')).'/messages:send', [
            'validateOnly' => true,
            'message' => ['topic' => 'ourspace-configuration-check', 'notification' => ['title' => 'OurSpace', 'body' => 'Configuration validation only.']],
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('Firebase configuration validation failed (HTTP '.$response->status().'). Check that Cloud Messaging HTTP v1 is enabled and the service account has permission.');
        }
    }

    private function accessToken(): string
    {
        return Cache::remember('firebase-access:'.hash('sha256', config('notifications.firebase_project_id').':'.config('notifications.firebase_credentials')), 3000, function (): string {
            $credentials = json_decode(file_get_contents(config('notifications.firebase_credentials')), true, flags: JSON_THROW_ON_ERROR);
            $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $header = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $encode(json_encode(['iss' => $credentials['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600]));
            if (! openssl_sign($header.'.'.$claims, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Unable to sign Firebase credentials.');
            }
            $response = Http::asForm()->connectTimeout(3)->timeout(8)->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $header.'.'.$claims.'.'.$encode($signature)]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException('Firebase authorization failed. Check the server configuration.');
            }

            return $response->json('access_token');
        });
    }

    public function send(string $token, string $id, array $data): bool
    {
        $response = Http::withToken($this->accessToken())->connectTimeout(3)->timeout(8)->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode(config('notifications.firebase_project_id')).'/messages:send', [
            'message' => ['token' => $token, 'notification' => ['title' => 'OurSpace', 'body' => $data['body']], 'data' => ['notification_id' => $id, 'path' => $data['path']], 'android' => ['ttl' => ($data['kind'] ?? '') === 'incoming_call' ? '0s' : '3600s', 'priority' => ($data['kind'] ?? '') === 'incoming_call' ? 'HIGH' : 'NORMAL', 'collapse_key' => $id, 'notification' => ['channel_id' => 'ourspace', 'tag' => $id, 'visibility' => 'PRIVATE']]],
        ]);
        if (collect($response->json('error.details', []))->contains(fn ($detail) => ($detail['errorCode'] ?? '') === 'UNREGISTERED')) {
            return false;
        }
        if (! $response->successful()) {
            throw new RuntimeException('Firebase delivery failed (HTTP '.$response->status().').');
        }

        return true;
    }
}
