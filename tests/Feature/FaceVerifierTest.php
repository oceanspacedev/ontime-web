<?php

namespace Tests\Feature;

use App\Models\CompanyPolicy;
use App\Services\Face\BasicFaceVerifier;
use App\Services\Face\CompreFaceVerifier;
use App\Services\Face\FaceServiceUnavailable;
use App\Services\Face\FaceVerifier;
use App\Services\FaceRecognitionService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FaceVerifierTest extends TestCase
{
    private const VERIFY_URL = 'compreface.test/api/v1/verification/verify*';

    private const DETECT_URL = 'compreface.test/api/v1/detection/detect*';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Http::preventStrayRequests();

        config([
            'services.face.driver' => 'compreface',
            'services.face.compreface.url' => 'http://compreface.test/',
            'services.face.compreface.verification_key' => 'verify-key',
            'services.face.compreface.detection_key' => 'detect-key',
            'services.face.compreface.timeout' => 5,
            'services.face.compreface.det_prob_threshold' => 0.8,
        ]);
    }

    private function storeImage(string $name): string
    {
        return UploadedFile::fake()->image($name, 60, 60)->store('faces', 'public');
    }

    public function test_driver_binding_follows_config(): void
    {
        $this->assertInstanceOf(CompreFaceVerifier::class, app(FaceVerifier::class));

        config(['services.face.driver' => 'basic']);
        $this->assertInstanceOf(BasicFaceVerifier::class, app(FaceVerifier::class));

        config(['services.face.driver' => null]);
        $this->assertInstanceOf(BasicFaceVerifier::class, app(FaceVerifier::class));
    }

    public function test_basic_driver_describes_itself_and_keeps_todays_results(): void
    {
        $driver = app(BasicFaceVerifier::class);
        $path = $this->storeImage('master.jpg');

        $this->assertSame('basic', $driver->name());
        $this->assertFalse($driver->supportsPose());
        $this->assertFalse($driver->isAccurate());

        $match = $driver->verify($path, $path, 60);

        $this->assertTrue($match->matched);
        $this->assertSame(100, $match->score);
        $this->assertSame('Verifikasi wajah berhasil (Skor: 100%, Threshold: 60%).', $match->notes);

        $missing = $driver->verify('faces/missing.jpg', $path, 60);

        $this->assertFalse($missing->matched);
        $this->assertSame('Gagal membaca berkas foto master atau foto selfie dari storage.', $missing->notes);
    }

    public function test_basic_driver_cannot_detect_pose(): void
    {
        $this->expectException(FaceServiceUnavailable::class);
        $this->expectExceptionMessage('Driver wajah dasar tidak bisa membaca arah kepala.');

        app(BasicFaceVerifier::class)->detect($this->storeImage('frame.jpg'));
    }

    public function test_compreface_driver_describes_itself(): void
    {
        $driver = app(CompreFaceVerifier::class);

        $this->assertSame('compreface', $driver->name());
        $this->assertTrue($driver->supportsPose());
        $this->assertTrue($driver->isAccurate());
    }

    public function test_compreface_verify_maps_the_best_similarity_to_a_score(): void
    {
        Http::fake([self::VERIFY_URL => Http::response([
            'result' => [[
                'source_image_face' => ['box' => ['probability' => 0.99]],
                'face_matches' => [
                    ['similarity' => 0.41],
                    ['similarity' => 0.873],
                ],
            ]],
        ])]);

        $match = app(CompreFaceVerifier::class)->verify($this->storeImage('selfie.jpg'), $this->storeImage('master.jpg'), 80);

        $this->assertTrue($match->matched);
        $this->assertSame(87, $match->score);
        $this->assertSame('Verifikasi wajah berhasil (Skor: 87%, Threshold: 80%).', $match->notes);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'http://compreface.test/api/v1/verification/verify?')
                && str_contains($request->url(), 'limit=1')
                && str_contains($request->url(), 'det_prob_threshold=0.8')
                && $request->hasHeader('x-api-key', 'verify-key')
                && $request->isMultipart()
                && $request->hasFile('source_image')
                && $request->hasFile('target_image');
        });
    }

    public function test_compreface_verify_applies_the_threshold(): void
    {
        Http::fake([self::VERIFY_URL => Http::response([
            'result' => [['face_matches' => [['similarity' => 0.59]]]],
        ])]);

        $driver = app(CompreFaceVerifier::class);
        $selfie = $this->storeImage('selfie.jpg');
        $master = $this->storeImage('master.jpg');

        $match = $driver->verify($selfie, $master, 60);

        $this->assertFalse($match->matched);
        $this->assertSame(59, $match->score);
        $this->assertSame('Tingkat kemiripan wajah rendah (Skor: 59%, Threshold minimum: 60%).', $match->notes);

        $this->assertTrue($driver->verify($selfie, $master, 59)->matched);
    }

    public function test_compreface_verify_without_matches_scores_zero(): void
    {
        Http::fake([self::VERIFY_URL => Http::response(['result' => [['face_matches' => []]]])]);

        $match = app(CompreFaceVerifier::class)->verify($this->storeImage('selfie.jpg'), $this->storeImage('master.jpg'), 60);

        $this->assertFalse($match->matched);
        $this->assertSame(0, $match->score);
    }

    public function test_compreface_verify_reports_a_missing_face(): void
    {
        Http::fake([self::VERIFY_URL => Http::response([
            'message' => 'No face is found in the given image',
            'code' => 28,
        ], 400)]);

        $match = app(CompreFaceVerifier::class)->verify($this->storeImage('selfie.jpg'), $this->storeImage('master.jpg'), 60);

        $this->assertFalse($match->matched);
        $this->assertSame(0, $match->score);
        $this->assertSame('Wajah tidak terdeteksi pada foto selfie.', $match->notes);
    }

    public function test_compreface_verify_does_not_call_the_service_for_unreadable_files(): void
    {
        Http::fake();

        $match = app(CompreFaceVerifier::class)->verify('faces/missing.jpg', $this->storeImage('master.jpg'), 60);

        $this->assertFalse($match->matched);
        $this->assertSame('Gagal membaca berkas foto master atau foto selfie dari storage.', $match->notes);
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function unavailableStatuses(): array
    {
        return [
            'unauthorized' => [401],
            'forbidden' => [403],
            'server error' => [500],
            'bad gateway' => [502],
        ];
    }

    #[DataProvider('unavailableStatuses')]
    public function test_compreface_verify_throws_when_the_service_fails(int $status): void
    {
        Http::fake([self::VERIFY_URL => Http::response(['message' => 'error'], $status)]);

        $this->expectException(FaceServiceUnavailable::class);

        app(CompreFaceVerifier::class)->verify($this->storeImage('selfie.jpg'), $this->storeImage('master.jpg'), 60);
    }

    public function test_compreface_verify_throws_on_connection_errors(): void
    {
        Http::fake([self::VERIFY_URL => Http::failedConnection()]);

        $this->expectException(FaceServiceUnavailable::class);

        app(CompreFaceVerifier::class)->verify($this->storeImage('selfie.jpg'), $this->storeImage('master.jpg'), 60);
    }

    public function test_compreface_verify_names_the_missing_configuration(): void
    {
        config([
            'services.face.compreface.url' => null,
            'services.face.compreface.verification_key' => '',
        ]);
        Http::fake();

        try {
            app(CompreFaceVerifier::class)->verify($this->storeImage('selfie.jpg'), $this->storeImage('master.jpg'), 60);
            $this->fail('FaceServiceUnavailable was not thrown.');
        } catch (FaceServiceUnavailable $e) {
            $this->assertStringContainsString('COMPREFACE_URL', $e->getMessage());
            $this->assertStringContainsString('COMPREFACE_VERIFICATION_KEY', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_compreface_detect_names_the_missing_detection_key(): void
    {
        config(['services.face.compreface.detection_key' => null]);

        $this->expectException(FaceServiceUnavailable::class);
        $this->expectExceptionMessage('COMPREFACE_DETECTION_KEY');

        app(CompreFaceVerifier::class)->detect($this->storeImage('frame.jpg'));
    }

    public function test_compreface_detect_maps_faces_and_pose(): void
    {
        Http::fake([self::DETECT_URL => Http::response([
            'result' => [
                ['box' => ['probability' => 0.99], 'pose' => ['yaw' => -32.5, 'pitch' => 4.25, 'roll' => 1.5]],
                ['box' => ['probability' => 0.91], 'pose' => ['yaw' => 2, 'pitch' => 0, 'roll' => 0]],
            ],
        ])]);

        $detection = app(CompreFaceVerifier::class)->detect($this->storeImage('frame.jpg'));

        $this->assertSame(2, $detection->faces);
        $this->assertSame(-32.5, $detection->yaw);
        $this->assertSame(4.25, $detection->pitch);
        $this->assertSame(1.5, $detection->roll);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'http://compreface.test/api/v1/detection/detect?')
                && str_contains($request->url(), 'face_plugins=pose')
                && str_contains($request->url(), 'det_prob_threshold=0.8')
                && $request->hasHeader('x-api-key', 'detect-key')
                && $request->hasFile('file');
        });
    }

    public function test_compreface_detect_returns_zero_faces_when_none_is_found(): void
    {
        Http::fake([self::DETECT_URL => Http::response([
            'message' => 'No face is found in the given image',
            'code' => 28,
        ], 400)]);

        $detection = app(CompreFaceVerifier::class)->detect($this->storeImage('frame.jpg'));

        $this->assertSame(0, $detection->faces);
        $this->assertNull($detection->yaw);
    }

    public function test_compreface_detect_throws_on_server_errors(): void
    {
        Http::fake([self::DETECT_URL => Http::response('down', 503)]);

        $this->expectException(FaceServiceUnavailable::class);

        app(CompreFaceVerifier::class)->detect($this->storeImage('frame.jpg'));
    }

    public function test_face_recognition_service_wraps_the_bound_driver(): void
    {
        Http::fake([self::VERIFY_URL => Http::response([
            'result' => [['face_matches' => [['similarity' => 0.7]]]],
        ])]);

        $result = app(FaceRecognitionService::class)->verifyFace(
            $this->storeImage('selfie.jpg'),
            $this->storeImage('master.jpg'),
            new CompanyPolicy(['face_match_threshold' => 75]),
        );

        $this->assertSame([
            'is_matched' => false,
            'score' => 70,
            'notes' => 'Tingkat kemiripan wajah rendah (Skor: 70%, Threshold minimum: 75%).',
        ], $result);
    }

    public function test_face_recognition_service_rethrows_service_outages(): void
    {
        Http::fake([self::VERIFY_URL => Http::response([], 500)]);

        $this->expectException(FaceServiceUnavailable::class);

        app(FaceRecognitionService::class)->verifyFace($this->storeImage('selfie.jpg'), $this->storeImage('master.jpg'));
    }
}
