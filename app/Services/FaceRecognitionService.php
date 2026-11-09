<?php

namespace App\Services;

use App\Models\CompanyPolicy;
use App\Services\Face\FaceServiceUnavailable;
use App\Services\Face\FaceVerifier;

/**
 * Thin wrapper over the configured face driver (config services.face.driver) that keeps the
 * array shape older callers expect.
 */
class FaceRecognitionService
{
    public const DEFAULT_THRESHOLD = 60;

    public function __construct(private FaceVerifier $faceVerifier) {}

    /**
     * Compare a captured selfie photo with a worker's registered master face photo.
     *
     * @param  string|null  $selfiePath  Storage relative path or absolute path to selfie
     * @param  string|null  $masterPhotoPath  Storage relative path to registered master photo
     * @return array{is_matched: bool, score: int, notes: string}
     *
     * @throws FaceServiceUnavailable
     */
    public function verifyFace(?string $selfiePath, ?string $masterPhotoPath, ?CompanyPolicy $policy = null): array
    {
        if (empty($masterPhotoPath)) {
            return [
                'is_matched' => false,
                'score' => 0,
                'notes' => 'Foto Master Wajah belum didaftarkan pada profil karyawan.',
            ];
        }

        if (empty($selfiePath)) {
            return [
                'is_matched' => false,
                'score' => 0,
                'notes' => 'Foto selfie absensi tidak ditemukan.',
            ];
        }

        $match = $this->faceVerifier->verify($selfiePath, $masterPhotoPath, self::thresholdFor($policy));

        return [
            'is_matched' => $match->matched,
            'score' => $match->score,
            'notes' => $match->notes,
        ];
    }

    /**
     * The minimum similarity in percent for the company, 60 when the policy has none.
     */
    public static function thresholdFor(?CompanyPolicy $policy): int
    {
        return (int) ($policy?->face_match_threshold ?? self::DEFAULT_THRESHOLD);
    }
}
