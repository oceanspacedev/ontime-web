<?php

namespace Tests\Feature;

use App\Models\CompanyPolicy;
use App\Models\User;
use App\Services\Face\FaceServiceUnavailable;
use App\Services\LivenessService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LivenessServiceTest extends TestCase
{
    private const VERIFY_URL = 'compreface.test/api/v1/verification/verify*';

    private const DETECT_URL = 'compreface.test/api/v1/detection/detect*';

    private const EXPIRED = 'Tantangan liveness sudah kedaluwarsa atau tidak dikenali. Mulai ulang verifikasi.';

    /**
     * Default poses per step: yaw and pitch in degrees.
     *
     * @var array<string, array{yaw: float, pitch: float}>
     */
    private const GOOD_POSES = [
        'front' => ['yaw' => 4.0, 'pitch' => 3.0],
        'left' => ['yaw' => 32.0, 'pitch' => -5.0],
        'right' => ['yaw' => -28.0, 'pitch' => 6.0],
    ];

    private User $user;

    private CompanyPolicy $policy;

    private string $masterPath;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Http::preventStrayRequests();

        config([
            'services.face.driver' => 'compreface',
            'services.face.compreface.url' => 'http://compreface.test',
            'services.face.compreface.verification_key' => 'verify-key',
            'services.face.compreface.detection_key' => 'detect-key',
            'services.face.liveness.ttl_seconds' => 120,
            'services.face.liveness.front_max_yaw' => 15,
            'services.face.liveness.side_min_yaw' => 20,
            'services.face.liveness.max_pitch' => 25,
        ]);

        $this->user = $this->makeUser(41);
        $this->policy = new CompanyPolicy(['face_match_threshold' => 70]);
        $this->masterPath = $this->storeImage('master.jpg');
    }

    private function makeUser(int $id): User
    {
        $user = User::factory()->make();
        $user->id = $id;

        return $user;
    }

    private function storeImage(string $name): string
    {
        return UploadedFile::fake()->image($name, 60, 60)->store('liveness', 'public');
    }

    private function service(): LivenessService
    {
        return app(LivenessService::class);
    }

    /**
     * @return list<string>
     */
    private function storeFrames(int $count = 3): array
    {
        return array_map(fn (int $index): string => $this->storeImage("frame-{$index}.jpg"), range(1, $count));
    }

    /**
     * @param  list<array{key: string}>  $steps
     * @param  array<string, array<string, mixed>>  $overrides  per step key: a full CompreFace detect response body or status
     */
    private function fakeCompreFace(array $steps, array $overrides = [], float $similarity = 0.92): void
    {
        $detections = Http::sequence();

        foreach ($steps as $step) {
            $override = $overrides[$step['key']] ?? null;

            if (isset($override['status'])) {
                $detections->push($override['body'] ?? [], $override['status']);

                continue;
            }

            $pose = array_merge(self::GOOD_POSES[$step['key']], $override ?? []);
            $faces = $pose['faces'] ?? 1;
            unset($pose['faces']);

            $detections->push([
                'result' => array_fill(0, $faces, ['box' => ['probability' => 0.99], 'pose' => $pose + ['roll' => 0]]),
            ]);
        }

        Http::fake([
            self::DETECT_URL => $detections,
            self::VERIFY_URL => Http::response(['result' => [['face_matches' => [['similarity' => $similarity]]]]]),
        ]);
    }

    /**
     * @param  list<array{key: string}>  $steps
     * @param  list<string>  $frames
     */
    private function frameFor(string $stepKey, array $steps, array $frames): string
    {
        $index = array_search($stepKey, array_column($steps, 'key'), true);

        return $frames[$index];
    }

    public function test_issue_challenge_returns_three_steps_in_random_order_and_caches_them(): void
    {
        $this->freezeTime();

        $challenge = $this->service()->issueChallenge($this->user, 'check_in');

        $this->assertTrue(Str::isUuid($challenge['challenge_id']));
        $this->assertSame(120, $challenge['expires_in']);
        $this->assertSame(now()->addSeconds(120)->toIso8601String(), $challenge['expires_at']);
        $this->assertCount(3, $challenge['steps']);

        $byKey = collect($challenge['steps'])->keyBy('key');
        $this->assertEqualsCanonicalizing(['front', 'left', 'right'], $byKey->keys()->all());
        $this->assertSame(['key' => 'front', 'label' => 'Hadap depan', 'instruction' => 'Lihat lurus ke kamera.'], $byKey['front']);
        $this->assertSame(['key' => 'left', 'label' => 'Tengok ke kiri', 'instruction' => 'Putar kepala ke kiri sampai pipi kanan terlihat jelas.'], $byKey['left']);
        $this->assertSame(['key' => 'right', 'label' => 'Tengok ke kanan', 'instruction' => 'Putar kepala ke kanan sampai pipi kiri terlihat jelas.'], $byKey['right']);

        $cached = Cache::get('liveness:'.$challenge['challenge_id']);
        $this->assertSame(41, $cached['user_id']);
        $this->assertSame('check_in', $cached['purpose']);
        $this->assertSame(array_column($challenge['steps'], 'key'), $cached['steps']);

        $this->travel(121)->seconds();
        $this->assertNull(Cache::get('liveness:'.$challenge['challenge_id']));
    }

    public function test_issue_challenge_produces_different_orders(): void
    {
        $orders = collect(range(1, 40))
            ->map(fn (): string => implode(',', array_column($this->service()->issueChallenge($this->user, 'check_out')['steps'], 'key')))
            ->unique();

        $this->assertGreaterThan(1, $orders->count());
    }

    public function test_issue_challenge_rejects_an_unknown_purpose(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service()->issueChallenge($this->user, 'lunch');
    }

    public function test_basic_driver_is_refused_before_anything_is_called(): void
    {
        config(['services.face.driver' => 'basic']);
        Http::fake();

        try {
            $this->service()->issueChallenge($this->user, 'check_in');
            $this->fail('FaceServiceUnavailable was not thrown.');
        } catch (FaceServiceUnavailable $e) {
            $this->assertSame('Liveness membutuhkan layanan wajah CompreFace. Hubungi admin.', $e->getMessage());
        }

        try {
            $this->service()->verify($this->user, (string) Str::uuid(), 'check_in', $this->storeFrames(), $this->masterPath, $this->policy);
            $this->fail('FaceServiceUnavailable was not thrown.');
        } catch (FaceServiceUnavailable $e) {
            $this->assertSame('Liveness membutuhkan layanan wajah CompreFace. Hubungi admin.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: float, 1: float}>
     */
    public static function oppositeSideYaws(): array
    {
        return [
            'left positive' => [32.0, -28.0],
            'left negative' => [-24.0, 21.0],
        ];
    }

    #[DataProvider('oppositeSideYaws')]
    public function test_verify_passes_with_front_and_opposite_sides_and_matches_the_front_frame(float $leftYaw, float $rightYaw): void
    {
        $challenge = $this->service()->issueChallenge($this->user, 'check_in');
        $frames = $this->storeFrames();
        $this->fakeCompreFace($challenge['steps'], ['left' => ['yaw' => $leftYaw], 'right' => ['yaw' => $rightYaw]], 0.92);

        $result = $this->service()->verify($this->user, $challenge['challenge_id'], 'check_in', $frames, $this->masterPath, $this->policy);

        $this->assertTrue($result->livenessPassed);
        $this->assertSame($this->frameFor('front', $challenge['steps'], $frames), $result->frontFramePath);
        $this->assertNotNull($result->faceMatch);
        $this->assertTrue($result->faceMatch->matched);
        $this->assertSame(92, $result->faceMatch->score);
        $this->assertSame('Verifikasi wajah berhasil (Skor: 92%, Threshold: 70%).', $result->faceMatch->notes);

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/verification/verify')
            && $request->hasHeader('x-api-key', 'verify-key'));
    }

    public function test_verify_reports_a_face_mismatch_with_the_policy_threshold(): void
    {
        $challenge = $this->service()->issueChallenge($this->user, 'check_out');
        $this->fakeCompreFace($challenge['steps'], similarity: 0.65);

        $result = $this->service()->verify($this->user, $challenge['challenge_id'], 'check_out', $this->storeFrames(), $this->masterPath, $this->policy);

        $this->assertTrue($result->livenessPassed);
        $this->assertFalse($result->faceMatch->matched);
        $this->assertSame(65, $result->faceMatch->score);
        $this->assertSame('Tingkat kemiripan wajah rendah (Skor: 65%, Threshold minimum: 70%).', $result->faceMatch->notes);
    }

    public function test_verify_is_single_use(): void
    {
        $challenge = $this->service()->issueChallenge($this->user, 'check_in');
        $frames = $this->storeFrames();
        $this->fakeCompreFace($challenge['steps']);

        $this->assertTrue($this->service()->verify($this->user, $challenge['challenge_id'], 'check_in', $frames, $this->masterPath, $this->policy)->livenessPassed);

        $again = $this->service()->verify($this->user, $challenge['challenge_id'], 'check_in', $frames, $this->masterPath, $this->policy);

        $this->assertFalse($again->livenessPassed);
        $this->assertSame(self::EXPIRED, $again->notes);
        $this->assertNull($again->frontFramePath);
        $this->assertNull($again->faceMatch);
    }

    public function test_verify_rejects_an_expired_challenge(): void
    {
        Http::fake();
        $challenge = $this->service()->issueChallenge($this->user, 'check_in');

        $this->travel(121)->seconds();

        $result = $this->service()->verify($this->user, $challenge['challenge_id'], 'check_in', $this->storeFrames(), $this->masterPath, $this->policy);

        $this->assertFalse($result->livenessPassed);
        $this->assertSame(self::EXPIRED, $result->notes);
        Http::assertNothingSent();
    }

    public function test_verify_rejects_an_unknown_challenge(): void
    {
        Http::fake();

        $result = $this->service()->verify($this->user, (string) Str::uuid(), 'check_in', $this->storeFrames(), $this->masterPath, $this->policy);

        $this->assertFalse($result->livenessPassed);
        $this->assertSame(self::EXPIRED, $result->notes);
        Http::assertNothingSent();
    }

    public function test_verify_rejects_a_challenge_of_another_user_and_consumes_it(): void
    {
        Http::fake();
        $challenge = $this->service()->issueChallenge($this->user, 'check_in');

        $result = $this->service()->verify($this->makeUser(99), $challenge['challenge_id'], 'check_in', $this->storeFrames(), $this->masterPath, $this->policy);

        $this->assertFalse($result->livenessPassed);
        $this->assertSame(self::EXPIRED, $result->notes);
        $this->assertNull(Cache::get('liveness:'.$challenge['challenge_id']));
        Http::assertNothingSent();
    }

    public function test_verify_rejects_a_challenge_issued_for_another_purpose(): void
    {
        Http::fake();
        $challenge = $this->service()->issueChallenge($this->user, 'check_in');

        $result = $this->service()->verify($this->user, $challenge['challenge_id'], 'check_out', $this->storeFrames(), $this->masterPath, $this->policy);

        $this->assertFalse($result->livenessPassed);
        $this->assertSame(self::EXPIRED, $result->notes);
        Http::assertNothingSent();
    }

    public function test_verify_rejects_a_wrong_frame_count_and_consumes_the_challenge(): void
    {
        Http::fake();
        $challenge = $this->service()->issueChallenge($this->user, 'check_in');

        $result = $this->service()->verify($this->user, $challenge['challenge_id'], 'check_in', $this->storeFrames(2), $this->masterPath, $this->policy);

        $this->assertFalse($result->livenessPassed);
        $this->assertSame('Jumlah foto liveness tidak sesuai. Mulai ulang verifikasi.', $result->notes);
        $this->assertNull(Cache::get('liveness:'.$challenge['challenge_id']));
        Http::assertNothingSent();
    }

    public function test_verify_rejects_a_frame_without_a_face(): void
    {
        $challenge = $this->service()->issueChallenge($this->user, 'check_in');
        $this->fakeCompreFace($challenge['steps'], ['left' => ['status' => 400, 'body' => ['message' => 'No face is found in the given image', 'code' => 28]]]);

        $result = $this->service()->verify($this->user, $challenge['challenge_id'], 'check_in', $this->storeFrames(), $this->masterPath, $this->policy);

        $this->assertFalse($result->livenessPassed);
        $this->assertSame('Wajah tidak terdeteksi dengan jelas pada langkah "Tengok ke kiri".', $result->notes);
        $this->assertNull($result->faceMatch);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/verification/verify'));
    }

    public function test_verify_rejects_a_frame_with_multiple_faces(): void
    {
        $challenge = $this->service()->issueChallenge($this->user, 'check_in');
        $this->fakeCompreFace($challenge['steps'], ['front' => ['faces' => 2]]);

        $result = $this->service()->verify($this->user, $challenge['challenge_id'], 'check_in', $this->storeFrames(), $this->masterPath, $this->policy);

        $this->assertFalse($result->livenessPassed);
        $this->assertSame('Terdeteksi lebih dari satu wajah pada langkah "Hadap depan".', $result->notes);
        $this->assertNull($result->faceMatch);
    }

    public function test_verify_rejects_side_frames_turned_the_same_way(): void
    {
        $challenge = $this->service()->issueChallenge($this->user, 'check_in');
        $this->fakeCompreFace($challenge['steps'], ['left' => ['yaw' => 30.0], 'right' => ['yaw' => 35.0]]);

        $result = $this->service()->verify($this->user, $challenge['challenge_id'], 'check_in', $this->storeFrames(), $this->masterPath, $this->policy);

        $sideKeys = array_values(array_diff(array_column($challenge['steps'], 'key'), ['front']));
        $secondSideLabel = $sideKeys[1] === 'left' ? 'Tengok ke kiri' : 'Tengok ke kanan';

        $this->assertFalse($result->livenessPassed);
        $this->assertSame("Gerakan kepala tidak sesuai pada langkah \"{$secondSideLabel}\". Ulangi dan ikuti instruksi dengan jelas.", $result->notes);
        $this->assertNull($result->faceMatch);
    }

    public function test_verify_rejects_a_side_frame_that_is_not_turned_enough(): void
    {
        $challenge = $this->service()->issueChallenge($this->user, 'check_in');
        $this->fakeCompreFace($challenge['steps'], ['right' => ['yaw' => -12.0]]);

        $result = $this->service()->verify($this->user, $challenge['challenge_id'], 'check_in', $this->storeFrames(), $this->masterPath, $this->policy);

        $this->assertFalse($result->livenessPassed);
        $this->assertSame('Gerakan kepala tidak sesuai pada langkah "Tengok ke kanan". Ulangi dan ikuti instruksi dengan jelas.', $result->notes);
    }

    public function test_verify_rejects_a_front_frame_that_is_turned_too_far(): void
    {
        $challenge = $this->service()->issueChallenge($this->user, 'check_in');
        $this->fakeCompreFace($challenge['steps'], ['front' => ['yaw' => -18.0]]);

        $result = $this->service()->verify($this->user, $challenge['challenge_id'], 'check_in', $this->storeFrames(), $this->masterPath, $this->policy);

        $this->assertFalse($result->livenessPassed);
        $this->assertSame('Gerakan kepala tidak sesuai pada langkah "Hadap depan". Ulangi dan ikuti instruksi dengan jelas.', $result->notes);
        $this->assertNull($result->frontFramePath);
    }

    public function test_verify_rejects_a_head_tilted_too_far(): void
    {
        $challenge = $this->service()->issueChallenge($this->user, 'check_in');
        $this->fakeCompreFace($challenge['steps'], ['left' => ['pitch' => 31.0]]);

        $result = $this->service()->verify($this->user, $challenge['challenge_id'], 'check_in', $this->storeFrames(), $this->masterPath, $this->policy);

        $this->assertFalse($result->livenessPassed);
        $this->assertSame('Gerakan kepala tidak sesuai pada langkah "Tengok ke kiri". Ulangi dan ikuti instruksi dengan jelas.', $result->notes);
    }

    public function test_verify_propagates_face_service_outages(): void
    {
        $challenge = $this->service()->issueChallenge($this->user, 'check_in');
        Http::fake([self::DETECT_URL => Http::response('down', 502)]);

        $this->expectException(FaceServiceUnavailable::class);

        $this->service()->verify($this->user, $challenge['challenge_id'], 'check_in', $this->storeFrames(), $this->masterPath, $this->policy);
    }
}
