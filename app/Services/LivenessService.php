<?php

namespace App\Services;

use App\Models\CompanyPolicy;
use App\Models\User;
use App\Services\Face\FaceDetection;
use App\Services\Face\FaceMatch;
use App\Services\Face\FaceServiceUnavailable;
use App\Services\Face\FaceVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Server verified active liveness: the user takes three photos in a random order (hadap depan,
 * tengok ke kiri, tengok ke kanan). A printed photo or another screen cannot turn its head.
 *
 * The check is sign agnostic because the yaw sign convention and mirrored front cameras make
 * "left" ambiguous: the two side frames must each be turned far enough in opposite directions.
 */
class LivenessService
{
    public const PURPOSES = ['check_in', 'check_out'];

    public const EXPIRED_MESSAGE = 'Tantangan liveness sudah kedaluwarsa atau tidak dikenali. Mulai ulang verifikasi.';

    public const FRAME_COUNT_MESSAGE = 'Jumlah foto liveness tidak sesuai. Mulai ulang verifikasi.';

    public const DRIVER_MESSAGE = 'Liveness membutuhkan layanan wajah CompreFace. Hubungi admin.';

    /**
     * @var array<string, array{label: string, instruction: string}>
     */
    private const STEPS = [
        'front' => ['label' => 'Hadap depan', 'instruction' => 'Lihat lurus ke kamera.'],
        'left' => ['label' => 'Tengok ke kiri', 'instruction' => 'Putar kepala ke kiri sampai pipi kanan terlihat jelas.'],
        'right' => ['label' => 'Tengok ke kanan', 'instruction' => 'Putar kepala ke kanan sampai pipi kiri terlihat jelas.'],
    ];

    public function __construct(private FaceVerifier $faceVerifier) {}

    /**
     * Issue a new challenge for the user and remember it for the configured TTL.
     *
     * @return array{challenge_id: string, steps: list<array{key: string, label: string, instruction: string}>, expires_at: string, expires_in: int}
     *
     * @throws FaceServiceUnavailable when the face driver cannot read the head pose
     * @throws InvalidArgumentException for an unknown purpose
     */
    public function issueChallenge(User $user, string $purpose): array
    {
        $this->ensurePoseSupport();
        $this->ensureKnownPurpose($purpose);

        $ttl = $this->ttlSeconds();
        $challengeId = (string) Str::uuid();
        $stepKeys = collect(array_keys(self::STEPS))->shuffle()->values()->all();
        $expiresAt = now()->addSeconds($ttl);

        Cache::put($this->cacheKey($challengeId), [
            'user_id' => $user->getKey(),
            'purpose' => $purpose,
            'steps' => $stepKeys,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $ttl);

        return [
            'challenge_id' => $challengeId,
            'steps' => array_map(fn (string $key): array => [
                'key' => $key,
                'label' => self::STEPS[$key]['label'],
                'instruction' => self::STEPS[$key]['instruction'],
            ], $stepKeys),
            'expires_at' => $expiresAt->toIso8601String(),
            'expires_in' => $ttl,
        ];
    }

    /**
     * Check the frames of a challenge and match the front frame with the master face photo.
     * The challenge is single use: it is forgotten whether the check passes or not.
     *
     * @param  list<string>  $framePaths  Storage paths in the same order as the challenge steps.
     *
     * @throws FaceServiceUnavailable
     */
    public function verify(User $user, string $challengeId, string $purpose, array $framePaths, string $masterPhotoPath, CompanyPolicy $policy): LivenessResult
    {
        $this->ensurePoseSupport();

        $challenge = Cache::pull($this->cacheKey($challengeId));

        if (! $this->isValidChallenge($challenge, $user, $purpose)) {
            return LivenessResult::failed(self::EXPIRED_MESSAGE);
        }

        /** @var list<string> $stepKeys */
        $stepKeys = $challenge['steps'];
        $framePaths = array_values($framePaths);

        if (count($framePaths) !== count($stepKeys)) {
            return LivenessResult::failed(self::FRAME_COUNT_MESSAGE);
        }

        $sideYaws = [];
        $frontFramePath = null;

        foreach ($stepKeys as $index => $stepKey) {
            $label = self::STEPS[$stepKey]['label'];
            $detection = $this->faceVerifier->detect((string) $framePaths[$index]);

            if ($detection->faces === 0) {
                return LivenessResult::failed("Wajah tidak terdeteksi dengan jelas pada langkah \"{$label}\".");
            }

            if ($detection->faces > 1) {
                return LivenessResult::failed("Terdeteksi lebih dari satu wajah pada langkah \"{$label}\".");
            }

            if (! $this->hasExpectedPose($stepKey, $detection)) {
                return LivenessResult::failed($this->wrongPoseMessage($label));
            }

            if ($stepKey === 'front') {
                $frontFramePath = (string) $framePaths[$index];

                continue;
            }

            if ($sideYaws !== [] && ($sideYaws[0] <=> 0) === ($detection->yaw <=> 0)) {
                return LivenessResult::failed($this->wrongPoseMessage($label));
            }

            $sideYaws[] = (float) $detection->yaw;
        }

        $faceMatch = $masterPhotoPath === ''
            ? new FaceMatch(false, 0, 'Foto Master Wajah belum didaftarkan pada profil karyawan.')
            : $this->faceVerifier->verify($frontFramePath, $masterPhotoPath, FaceRecognitionService::thresholdFor($policy));

        return new LivenessResult(true, 'Liveness terverifikasi: gerakan kepala sesuai instruksi.', $frontFramePath, $faceMatch);
    }

    /**
     * @throws FaceServiceUnavailable
     */
    private function ensurePoseSupport(): void
    {
        if (! $this->faceVerifier->supportsPose()) {
            throw new FaceServiceUnavailable(self::DRIVER_MESSAGE);
        }
    }

    private function ensureKnownPurpose(string $purpose): void
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException("Unknown liveness purpose [{$purpose}].");
        }
    }

    private function isValidChallenge(mixed $challenge, User $user, string $purpose): bool
    {
        return is_array($challenge)
            && (string) ($challenge['user_id'] ?? '') === (string) $user->getKey()
            && ($challenge['purpose'] ?? null) === $purpose
            && is_array($challenge['steps'] ?? null)
            && (int) ($challenge['expires_at'] ?? 0) >= now()->getTimestamp();
    }

    private function hasExpectedPose(string $stepKey, FaceDetection $detection): bool
    {
        if (! $detection->hasPose()) {
            return false;
        }

        if (abs($detection->pitch) > $this->setting('max_pitch', 25)) {
            return false;
        }

        if ($stepKey === 'front') {
            return abs($detection->yaw) <= $this->setting('front_max_yaw', 15);
        }

        return abs($detection->yaw) >= $this->setting('side_min_yaw', 20);
    }

    private function wrongPoseMessage(string $label): string
    {
        return "Gerakan kepala tidak sesuai pada langkah \"{$label}\". Ulangi dan ikuti instruksi dengan jelas.";
    }

    private function setting(string $name, float $default): float
    {
        return (float) config("services.face.liveness.{$name}", $default);
    }

    private function ttlSeconds(): int
    {
        return max(1, (int) config('services.face.liveness.ttl_seconds', 180));
    }

    private function cacheKey(string $challengeId): string
    {
        return "liveness:{$challengeId}";
    }
}
