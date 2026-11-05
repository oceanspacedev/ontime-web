<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterDeviceRequest;
use App\Http\Requests\Api\V1\UnregisterDeviceRequest;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;

class DeviceApiController extends Controller
{
    /**
     * Register the Expo push token of the calling install, or refresh it. A token that was last
     * used by another account on the same device moves to the caller.
     */
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $device = DeviceToken::query()->updateOrCreate(
            ['token' => $request->string('token')->toString()],
            [
                'user_id' => $request->user()->id,
                'platform' => $request->string('platform')->toString(),
                'last_seen_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Perangkat terdaftar untuk notifikasi.',
            'device' => [
                'token' => $device->token,
                'platform' => $device->platform,
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            ],
        ], $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Stop sending push notifications to this install (called before logout).
     */
    public function destroy(UnregisterDeviceRequest $request): JsonResponse
    {
        $request->user()->deviceTokens()
            ->where('token', $request->string('token')->toString())
            ->delete();

        return response()->json([
            'message' => 'Perangkat dihapus dari notifikasi.',
        ]);
    }
}
