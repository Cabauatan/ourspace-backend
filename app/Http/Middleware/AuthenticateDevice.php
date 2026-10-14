<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && preg_match('/^os_[A-Za-z0-9_-]{64}$/D', $token), 401);
        $device = Device::query()->with('couple')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        abort_unless($device && $device->couple, 401);

        $request->setUserResolver(fn () => $device);
        $request->attributes->set('device', $device);
        $request->attributes->set('couple', $device->couple);
        $request->attributes->set('partner_type', $device->partner_type);
        if (! $device->last_seen_at || $device->last_seen_at->lte(now()->subMinutes(5))) {
            Device::query()->whereKey($device->id)->whereNull('revoked_at')->update(['last_seen_at' => now()]);
            $device->last_seen_at = now();
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
