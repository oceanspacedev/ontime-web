<?php

namespace App\Services\Face;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Self-hosted CompreFace (https://github.com/exadel-inc/CompreFace) over its REST API.
 * Verification uses a "Verification" service key, pose detection a "Detection" service key.
 */
class CompreFaceVerifier implements FaceVerifier
{
    /**
     * CompreFace error code for "No face is found in the given image".
     */
    private const NO_FACE_CODE = 28;

    public function __construct(private FaceImageReader $images) {}

    public function name(): string
    {
        return 'compreface';
    }

    public function supportsPose(): bool
    {
        return true;
    }

    public function isAccurate(): bool
    {
        return true;
    }

    public function verify(string $selfiePath, string $masterPath, int $thresholdPercent): FaceMatch
    {
        $key = $this->configuredKey('verification_key', 'COMPREFACE_VERIFICATION_KEY');

        $selfie = $this->images->read($selfiePath);
        $master = $this->images->read($masterPath);

        if (! $selfie || ! $master) {
            return new FaceMatch(false, 0, 'Gagal membaca berkas foto master atau foto selfie dari storage.');
        }

        $response = $this->send(
            '/api/v1/verification/verify',
            ['limit' => 1, 'det_prob_threshold' => $this->detectionThreshold()],
            $key,
            ['source_image' => [$selfie, 'selfie.jpg'], 'target_image' => [$master, 'master.jpg']],
        );

        if ($response->status() === 400) {
            if ($this->isNoFaceResponse($response)) {
                return new FaceMatch(false, 0, 'Wajah tidak terdeteksi pada foto selfie.');
            }

            return new FaceMatch(false, 0, 'Foto tidak dapat diproses oleh layanan verifikasi wajah.');
        }

        $this->ensureSuccessful($response);

        $similarities = collect($response->json('result.0.face_matches', []))
            ->pluck('similarity')
            ->filter(fn (mixed $similarity): bool => is_numeric($similarity));

        $score = $similarities->isEmpty() ? 0 : (int) round(((float) $similarities->max()) * 100);
        $score = max(0, min(100, $score));
        $isMatched = $score >= $thresholdPercent;

        return new FaceMatch(
            $isMatched,
            $score,
            $isMatched
                ? "Verifikasi wajah berhasil (Skor: {$score}%, Threshold: {$thresholdPercent}%)."
                : "Tingkat kemiripan wajah rendah (Skor: {$score}%, Threshold minimum: {$thresholdPercent}%).",
        );
    }

    public function detect(string $imagePath): FaceDetection
    {
        $key = $this->configuredKey('detection_key', 'COMPREFACE_DETECTION_KEY');

        $image = $this->images->read($imagePath);

        if (! $image) {
            return new FaceDetection(0);
        }

        $response = $this->send(
            '/api/v1/detection/detect',
            ['face_plugins' => 'pose', 'det_prob_threshold' => $this->detectionThreshold()],
            $key,
            ['file' => [$image, 'frame.jpg']],
        );

        if ($response->status() === 400) {
            return new FaceDetection(0);
        }

        $this->ensureSuccessful($response);

        $faces = $response->json('result', []);

        if (! is_array($faces) || $faces === []) {
            return new FaceDetection(0);
        }

        $pose = $faces[0]['pose'] ?? [];

        return new FaceDetection(
            count($faces),
            $this->degrees($pose['yaw'] ?? null),
            $this->degrees($pose['pitch'] ?? null),
            $this->degrees($pose['roll'] ?? null),
        );
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, array{0: string, 1: string}>  $files  field => [contents, filename]
     *
     * @throws FaceServiceUnavailable
     */
    private function send(string $endpoint, array $query, string $key, array $files): Response
    {
        $request = Http::withHeaders(['x-api-key' => $key])
            ->acceptJson()
            ->timeout((int) config('services.face.compreface.timeout', 15));

        foreach ($files as $field => [$contents, $filename]) {
            $request = $request->attach($field, $contents, $filename);
        }

        try {
            return $request->post($this->baseUrl().$endpoint.'?'.http_build_query($query));
        } catch (ConnectionException $e) {
            Log::warning('CompreFace unreachable: '.$e->getMessage());

            throw new FaceServiceUnavailable('Layanan wajah CompreFace tidak dapat dihubungi.', previous: $e);
        }
    }

    /**
     * @throws FaceServiceUnavailable
     */
    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful() && is_array($response->json())) {
            return;
        }

        Log::warning('CompreFace request failed', ['status' => $response->status()]);

        throw new FaceServiceUnavailable(match (true) {
            in_array($response->status(), [401, 403], true) => 'Kunci API CompreFace ditolak. Periksa konfigurasi layanan wajah.',
            $response->serverError() => 'Layanan wajah CompreFace sedang bermasalah.',
            default => 'Layanan wajah CompreFace memberikan respons yang tidak dikenali.',
        });
    }

    private function isNoFaceResponse(Response $response): bool
    {
        return (int) $response->json('code') === self::NO_FACE_CODE
            || str_contains((string) $response->json('message'), 'No face');
    }

    /**
     * @throws FaceServiceUnavailable
     */
    private function baseUrl(): string
    {
        return rtrim((string) $this->configured('url', 'COMPREFACE_URL'), '/');
    }

    /**
     * Returns the key after checking that both the URL and the key are configured,
     * so the error names every missing setting at once.
     *
     * @throws FaceServiceUnavailable
     */
    private function configuredKey(string $keyName, string $envName): string
    {
        $missing = [];

        if (blank(config('services.face.compreface.url'))) {
            $missing[] = 'COMPREFACE_URL';
        }

        if (blank(config("services.face.compreface.{$keyName}"))) {
            $missing[] = $envName;
        }

        if ($missing !== []) {
            throw new FaceServiceUnavailable('Layanan wajah CompreFace belum dikonfigurasi. Isi '.implode(', ', $missing).' di .env.');
        }

        return (string) config("services.face.compreface.{$keyName}");
    }

    /**
     * @throws FaceServiceUnavailable
     */
    private function configured(string $name, string $envName): string
    {
        $value = config("services.face.compreface.{$name}");

        if (blank($value)) {
            throw new FaceServiceUnavailable("Layanan wajah CompreFace belum dikonfigurasi. Isi {$envName} di .env.");
        }

        return (string) $value;
    }

    private function detectionThreshold(): float
    {
        return (float) config('services.face.compreface.det_prob_threshold', 0.8);
    }

    private function degrees(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
