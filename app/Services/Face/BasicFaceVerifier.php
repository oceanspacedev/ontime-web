<?php

namespace App\Services\Face;

use GdImage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Built-in comparison of two downscaled photos (32x32 pixel colours). It is NOT real face
 * recognition: lighting and background weigh as much as the face. Use CompreFace for that.
 */
class BasicFaceVerifier implements FaceVerifier
{
    public function __construct(private FaceImageReader $images) {}

    public function name(): string
    {
        return 'basic';
    }

    public function supportsPose(): bool
    {
        return false;
    }

    public function isAccurate(): bool
    {
        return false;
    }

    public function verify(string $selfiePath, string $masterPath, int $thresholdPercent): FaceMatch
    {
        try {
            $masterContents = $this->images->read($masterPath);
            $selfieContents = $this->images->read($selfiePath);

            if (! $masterContents || ! $selfieContents) {
                return new FaceMatch(false, 0, 'Gagal membaca berkas foto master atau foto selfie dari storage.');
            }

            $masterImage = @imagecreatefromstring($masterContents);
            $selfieImage = @imagecreatefromstring($selfieContents);

            if (! $masterImage || ! $selfieImage) {
                return new FaceMatch(false, 0, 'Format foto tidak valid untuk pemrosesan citra.');
            }

            $score = $this->calculateSimilarityScore($masterImage, $selfieImage);

            imagedestroy($masterImage);
            imagedestroy($selfieImage);

            $isMatched = $score >= $thresholdPercent;

            return new FaceMatch(
                $isMatched,
                $score,
                $isMatched
                    ? "Verifikasi wajah berhasil (Skor: {$score}%, Threshold: {$thresholdPercent}%)."
                    : "Tingkat kemiripan wajah rendah (Skor: {$score}%, Threshold minimum: {$thresholdPercent}%).",
            );
        } catch (Throwable $e) {
            Log::error('Face recognition verification error: '.$e->getMessage());

            return new FaceMatch(false, 0, 'Terjadi kesalahan sistem saat memproses verifikasi wajah.');
        }
    }

    public function detect(string $imagePath): FaceDetection
    {
        throw new FaceServiceUnavailable('Driver wajah dasar tidak bisa membaca arah kepala.');
    }

    /**
     * Calculate a perceptual similarity score (0-100) between two GD images.
     */
    protected function calculateSimilarityScore(GdImage $img1, GdImage $img2): int
    {
        $targetWidth = 32;
        $targetHeight = 32;

        $thumb1 = imagecreatetruecolor($targetWidth, $targetHeight);
        $thumb2 = imagecreatetruecolor($targetWidth, $targetHeight);

        imagecopyresampled($thumb1, $img1, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($img1), imagesy($img1));
        imagecopyresampled($thumb2, $img2, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($img2), imagesy($img2));

        $diffSum = 0;
        $maxDiff = $targetWidth * $targetHeight * 255 * 3;

        for ($x = 0; $x < $targetWidth; $x++) {
            for ($y = 0; $y < $targetHeight; $y++) {
                $rgb1 = imagecolorat($thumb1, $x, $y);
                $rgb2 = imagecolorat($thumb2, $x, $y);

                $diffSum += abs((($rgb1 >> 16) & 0xFF) - (($rgb2 >> 16) & 0xFF))
                    + abs((($rgb1 >> 8) & 0xFF) - (($rgb2 >> 8) & 0xFF))
                    + abs(($rgb1 & 0xFF) - ($rgb2 & 0xFF));
            }
        }

        imagedestroy($thumb1);
        imagedestroy($thumb2);

        $score = (int) round((1 - ($diffSum / $maxDiff)) * 100);

        return max(0, min(100, $score));
    }
}
