<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadAvatarRequest;
use App\Http\Resources\CoupleProfileResource;
use App\Services\AvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AvatarController extends Controller
{
    public function show(Request $request, string $partner): StreamedResponse
    {
        $path = $request->attributes->get('couple')->{$partner.'_avatar'};
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(UploadAvatarRequest $request, string $partner, AvatarService $avatars): JsonResponse
    {
        $couple = $avatars->update($request->user(), $partner, $request->file('avatar'));

        return response()->json(['success' => true, 'message' => 'Partner photo updated.', 'data' => (new CoupleProfileResource($couple))->resolve($request)]);
    }

    public function destroy(Request $request, string $partner, AvatarService $avatars): JsonResponse
    {
        $couple = $avatars->update($request->user(), $partner, null);

        return response()->json(['success' => true, 'message' => 'Partner photo removed.', 'data' => (new CoupleProfileResource($couple))->resolve($request)]);
    }
}
