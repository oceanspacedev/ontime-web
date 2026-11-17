<?php

namespace App\Http\Controllers;

use App\Services\FileNamingService;
use App\Services\UploadStorageException;
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
     *
     * The photo must be a JPEG, PNG or WebP image of at most {@see SelfieUploadToken::MAX_KILOBYTES} KB,
     * the same limit as the API check-in photo. The type is read from the bytes and decides the
     * stored extension. Rejections answer 422 with {success: false, message}, which the camera
     * components show as is.
     *
     * An account that may no longer sign in (switched off, or its Klien switched off) gets a 403 before
     * anything is stored, like the panel and the API refuse it. A write the disk refuses without
     * throwing answers 500 and issues no token.
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

        if (! $user->canSignIn()) {
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda tidak aktif. Hubungi admin untuk mengaktifkannya kembali.',
            ], 403);
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

                if ($file->getSize() > SelfieUploadToken::MAX_KILOBYTES * 1024) {
                    return $this->photoTooLarge();
                }

                $extension = $this->detectedExtension(@getimagesize($file->getRealPath()));

                if ($extension === null) {
                    return $this->notAnImage();
                }

                $path = FileNamingService::storeUploadedFile($file, 'attendance-photos', 'ATT', $employeeCode, $disk, $extension);
            } elseif (filled($photoBase64 = $request->input('photo_base64')) && is_string($photoBase64)) {
                if (str_contains($photoBase64, 'base64,')) {
                    $photoBase64 = explode('base64,', $photoBase64)[1] ?? '';
                }

                $decoded = base64_decode(str_replace(' ', '+', trim($photoBase64)), true);

                if ($decoded === false || $decoded === '') {
                    return $this->invalidPhoto();
                }

                if (strlen($decoded) > SelfieUploadToken::MAX_KILOBYTES * 1024) {
                    return $this->photoTooLarge();
                }

                $extension = $this->detectedExtension(@getimagesizefromstring($decoded));

                if ($extension === null) {
                    return $this->notAnImage();
                }

                $path = 'attendance-photos/'.FileNamingService::generateFileName('ATT', $employeeCode, 'selfie.'.$extension);

                if (Storage::disk($disk)->put($path, $decoded) !== true) {
                    throw new UploadStorageException;
                }
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

    /**
     * The extension for an image whose bytes are JPEG, PNG or WebP, or null for anything else.
     *
     * The type comes from the file's content, never from the client's file name or MIME header.
     *
     * @param  array<int|string, mixed>|false  $imageInfo  The result of getimagesize().
     */
    private function detectedExtension(array|false $imageInfo): ?string
    {
        if ($imageInfo === false || ($imageInfo[0] ?? 0) < 1 || ($imageInfo[1] ?? 0) < 1) {
            return null;
        }

        return SelfieUploadToken::ALLOWED_IMAGE_TYPES[$imageInfo[2] ?? null] ?? null;
    }

    private function invalidPhoto(): JsonResponse
    {
        return $this->rejected('Foto selfie tidak valid atau gagal diterima. Ambil ulang foto.');
    }

    private function notAnImage(): JsonResponse
    {
        return $this->rejected('Foto selfie harus berupa gambar jpeg, png, atau webp. Ambil ulang foto.');
    }

    private function photoTooLarge(): JsonResponse
    {
        return $this->rejected('Foto selfie maksimal berukuran 5MB. Ambil ulang foto.');
    }

    private function rejected(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 422);
    }
}
