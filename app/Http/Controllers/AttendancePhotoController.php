<?php

namespace App\Http\Controllers;

use App\Services\FileNamingService;
use App\Support\SelfieUploadToken;
use App\Support\StorageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AttendancePhotoController extends Controller
{
    /**
     * Store a selfie from the panel camera and answer with an upload token for check-in.
     *
     * The storage path is never returned: check-in only accepts the token, which is bound to the
     * logged-in user and expires after {@see SelfieUploadToken::VALID_FOR_MINUTES} minutes.
     *
     * The login is checked here instead of with the "auth" middleware: outside "api/*" the exception
     * handler answers guests with a redirect to a "login" route this app does not have, while the
     * camera component needs a JSON 401.
     */
    public function upload(Request $request): JsonResponse
    {
        $user = $request->user('web');

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi Anda telah berakhir. Silakan login kembali.',
            ], 401);
        }

        try {
            $employeeCode = $user->employee?->employee_code ?? (string) $user->id;
            $disk = config('filesystems.default');
            $path = null;

            if ($request->hasFile('photo')) {
                $file = $request->file('photo');

                if (! $file->isValid()) {
                    return $this->invalidPhoto();
                }

                $path = FileNamingService::storeUploadedFile($file, 'attendance-photos', 'ATT', $employeeCode, $disk);
            } elseif (filled($photoBase64 = $request->input('photo_base64')) && is_string($photoBase64)) {
                if (str_contains($photoBase64, 'base64,')) {
                    $photoBase64 = explode('base64,', $photoBase64)[1] ?? '';
                }

                $decoded = base64_decode(str_replace(' ', '+', trim($photoBase64)), true);

                if ($decoded === false || $decoded === '') {
                    return $this->invalidPhoto();
                }

                $path = 'attendance-photos/'.FileNamingService::generateFileName('ATT', $employeeCode, 'selfie.jpg');
                Storage::disk($disk)->put($path, $decoded);
            }

            if ($path === null) {
                return $this->invalidPhoto();
            }

            return response()->json([
                'success' => true,
                'token' => SelfieUploadToken::issue($path, $user),
                'url' => StorageUrl::for($path, $disk),
            ]);
        } catch (Throwable $exception) {
            Log::error('Upload selfie error: '.$exception->getMessage(), [
                'exception' => $exception,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Foto selfie gagal disimpan. Coba lagi beberapa saat.',
            ], 500);
        }
    }

    private function invalidPhoto(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Foto selfie tidak valid atau gagal diterima. Ambil ulang foto.',
        ], 422);
    }
}
