<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\OtpRequestRequest;
use App\Http\Requests\Api\V1\OtpVerifyRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\ApiOtpLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    private const INACTIVE_MESSAGE = 'Akun Anda tidak aktif. Hubungi admin untuk mengaktifkannya kembali.';

    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Credentials do not match our records.',
            ], 401);
        }

        $user = Auth::user();

        if (! $user->is_active) {
            Auth::guard('web')->logout();

            return response()->json(['message' => self::INACTIVE_MESSAGE], 403);
        }

        return $this->loginResponse($user);
    }

    /**
     * Send a one-time code over WhatsApp or email. Like the web login pages, the answer is the same
     * whether or not the number or address belongs to an account.
     */
    public function requestOtp(OtpRequestRequest $request, ApiOtpLoginService $otpLogin): JsonResponse
    {
        $channel = (string) $request->validated('channel');

        if (! ApiOtpLoginService::isChannelConfigured($channel)) {
            return response()->json([
                'message' => $channel === ApiOtpLoginService::CHANNEL_WHATSAPP
                    ? 'Login dengan WhatsApp belum tersedia. Masuk dengan email dan kata sandi.'
                    : 'Login dengan email belum tersedia. Masuk dengan email dan kata sandi.',
            ], 503);
        }

        $issued = $otpLogin->request($channel, $request->destination(), (string) $request->ip());

        if ($issued['ok']) {
            return response()->json([
                'message' => $channel === ApiOtpLoginService::CHANNEL_WHATSAPP
                    ? 'Kode OTP dikirim ke WhatsApp.'
                    : 'Jika email terdaftar, kode OTP sudah dikirim ke email tersebut.',
                'channel' => $channel,
                'challenge_id' => $issued['challenge_id'],
                'expires_in' => $issued['expires_in'],
            ]);
        }

        [$field, $invalidMessage] = $channel === ApiOtpLoginService::CHANNEL_WHATSAPP
            ? ['phone', 'Nomor HP tidak valid.']
            : ['email', 'Alamat email tidak valid.'];

        return match ($issued['status']) {
            'invalid_destination' => response()->json(['message' => $invalidMessage, 'errors' => [$field => [$invalidMessage]]], 422),
            'rate_limited' => $this->retryLater('Terlalu banyak permintaan OTP. Silakan coba lagi nanti.', 429, $issued['retry_after']),
            'busy' => $this->retryLater('Pembuatan OTP sedang sibuk. Coba lagi sebentar lagi.', 503, $issued['retry_after']),
            default => response()->json(['message' => 'Kode OTP belum bisa dikirim. Coba lagi sebentar lagi.'], 503),
        };
    }

    public function verifyOtp(OtpVerifyRequest $request, ApiOtpLoginService $otpLogin): JsonResponse
    {
        $verified = $otpLogin->verify(
            (string) $request->validated('challenge_id'),
            (string) $request->validated('code'),
            (string) $request->ip(),
        );

        if ($verified['ok']) {
            return $this->loginResponse($verified['user']);
        }

        return match ($verified['status']) {
            'inactive' => response()->json(['message' => self::INACTIVE_MESSAGE], 403),
            'unknown_account' => response()->json([
                'message' => 'Nomor belum terdaftar untuk login. Hubungi administrator.',
            ], 401),
            'rate_limited' => $this->retryLater('Terlalu banyak percobaan kode yang salah. Minta kode OTP baru.', 429, $verified['retry_after']),
            'busy' => $this->retryLater('Verifikasi OTP sedang diproses. Coba lagi sebentar lagi.', 503, $verified['retry_after']),
            default => response()->json(['message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'], 401),
        };
    }

    private function loginResponse(User $user): JsonResponse
    {
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token_type' => 'Bearer',
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    private function retryLater(string $message, int $status, int $retryAfter): JsonResponse
    {
        $response = response()->json(['message' => $message, 'retry_after' => $retryAfter], $status);

        return $retryAfter > 0 ? $response->header('Retry-After', (string) $retryAfter) : $response;
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
