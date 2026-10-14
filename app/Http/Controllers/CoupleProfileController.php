<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCoupleProfileRequest;
use App\Http\Resources\CoupleProfileResource;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoupleProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'Couple profile.', 'data' => (new CoupleProfileResource($request->attributes->get('couple')))->resolve($request)]);
    }

    public function update(UpdateCoupleProfileRequest $request, DeviceService $devices): JsonResponse
    {
        $couple = $devices->updateProfile($request->user(), $request->validated());

        return response()->json(['success' => true, 'message' => 'Couple profile updated.', 'data' => (new CoupleProfileResource($couple))->resolve($request)]);
    }
}
