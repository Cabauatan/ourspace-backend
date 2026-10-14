<?php

use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['device' => AuthenticateDevice::class]);
        $middleware->prependToPriorityList(
            before: [ThrottleRequests::class, ThrottleRequestsWithRedis::class],
            prepend: AuthenticateDevice::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['code', 'token', 'setup_code', 'recovery_code']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (Response $response): Response {
            if (! request()->is('api/*') || $response->getStatusCode() < 400) {
                return $response;
            }

            $status = $response->getStatusCode();
            $original = json_decode($response->getContent(), true) ?? [];
            $message = match ($status) {
                401 => 'This device is not authorized.',
                403 => 'This action is not permitted.',
                404 => 'The requested resource was not found.',
                405 => 'This request method is not supported.',
                413 => 'This upload is too large. Please select smaller files.',
                422 => 'Validation failed.',
                429 => 'Too many requests. Please try again shortly.',
                default => $status >= 500 ? 'OurSpace is temporarily unavailable.' : 'The request could not be completed.',
            };
            $payload = ['success' => false, 'message' => $message];
            if ($status === 422) {
                $payload['errors'] = $original['errors'] ?? [];
            }

            $response->setContent(json_encode($payload));
            $response->headers->set('Content-Type', 'application/json');

            return $response;
        });
    })->create();
