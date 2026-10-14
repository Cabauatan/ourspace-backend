<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateSpaceRequest;
use App\Http\Requests\RegisterDeviceRequest;
use App\Http\Requests\RevokeDeviceRequest;
use App\Http\Resources\CoupleProfileResource;
use App\Http\Resources\DeviceResource;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function __construct(private readonly DeviceService $devices) {}

    public function register(RegisterDeviceRequest $request): JsonResponse
    {
        return $this->registrationResponse($request, false);
    }

    public function createSpace(CreateSpaceRequest $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Save your private recovery code.', 'data' => $this->devices->createSpace($request->validated())], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function replace(RegisterDeviceRequest $request): JsonResponse
    {
        return $this->registrationResponse($request, true);
    }

    private function registrationResponse(RegisterDeviceRequest $request, bool $replace): JsonResponse
    {
        $result = $this->devices->register($request->validated(), $replace);

        return response()->json([
            'success' => true,
            'message' => $replace ? 'Phone replaced. Previous tokens for this partner are revoked.' : 'Device registered.',
            'data' => [
                'device' => (new DeviceResource($result['device']))->resolve($request),
                'couple' => (new CoupleProfileResource($result['couple']))->resolve($request),
                'token' => $result['token'],
            ],
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    public function current(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Current device.', 'data' => [
            'device' => (new DeviceResource($request->user()))->resolve($request),
            'couple' => (new CoupleProfileResource($request->attributes->get('couple')))->resolve($request),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $devices = $request->attributes->get('couple')->devices()->orderByDesc('registered_at')->orderByDesc('id')->get();

        return response()->json(['success' => true, 'message' => 'Registered devices.', 'data' => DeviceResource::collection($devices)->resolve($request)]);
    }

    public function revoke(RevokeDeviceRequest $request): JsonResponse
    {
        $device = $this->devices->revoke($request->user(), $request->validated('device_id'));

        return response()->json(['success' => true, 'message' => 'Device revoked.', 'data' => (new DeviceResource($device))->resolve($request)]);
    }
}
