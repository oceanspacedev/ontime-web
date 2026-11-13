<?php

namespace Tests\Feature;

use App\Support\StorageUrl;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stored photos and attachments open in the browser instead of downloading, on every disk.
 */
class StorageUrlTest extends TestCase
{
    public function test_s3_links_ask_for_an_inline_file_of_the_right_type(): void
    {
        config([
            'filesystems.default' => 's3',
            'filesystems.disks.s3' => [
                'driver' => 's3',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'region' => 'us-east-1',
                'bucket' => 'ontime-test',
                'endpoint' => 'https://storage.contoh.test',
                'use_path_style_endpoint' => true,
                'visibility' => 'private',
            ],
        ]);
        Storage::forgetDisk('s3');

        $pdfUrl = (string) StorageUrl::for('leave-requests/attachments/surat.pdf', 's3');
        $photoUrl = (string) StorageUrl::for('attendance-photos/selfie.jpg', 's3');

        $this->assertStringStartsWith('https://storage.contoh.test/ontime-test/leave-requests/attachments/surat.pdf?', $pdfUrl);
        $this->assertStringContainsString('response-content-disposition=inline', $pdfUrl);
        $this->assertStringContainsString('response-content-type=application%2Fpdf', $pdfUrl);
        $this->assertStringContainsString('X-Amz-Signature=', $pdfUrl);
        $this->assertStringContainsString('response-content-type=image%2Fjpeg', $photoUrl);
    }

    public function test_local_disk_shows_pdfs_inline_without_the_sandbox(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::disk('local')->put('leave-requests/attachments/uat-surat.pdf', '%PDF-1.4');

        try {
            $response = $this->get((string) StorageUrl::for('leave-requests/attachments/uat-surat.pdf'));

            $response->assertOk()
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Content-Disposition', 'inline; filename=uat-surat.pdf');
            $this->assertStringNotContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        } finally {
            Storage::disk('local')->delete('leave-requests/attachments/uat-surat.pdf');
        }
    }

    public function test_local_disk_keeps_other_files_sandboxed(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::disk('local')->put('attendance-photos/uat-selfie.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        try {
            $response = $this->get((string) StorageUrl::for('attendance-photos/uat-selfie.svg'));

            $response->assertOk();
            $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        } finally {
            Storage::disk('local')->delete('attendance-photos/uat-selfie.svg');
        }
    }

    public function test_files_left_on_the_public_disk_keep_their_link(): void
    {
        config(['filesystems.default' => 'local']);
        Storage::disk('public')->put('leave-requests/attachments/uat-lama.pdf', '%PDF-1.4');

        try {
            $this->assertSame(
                Storage::disk('public')->url('leave-requests/attachments/uat-lama.pdf'),
                StorageUrl::for('leave-requests/attachments/uat-lama.pdf'),
            );
        } finally {
            Storage::disk('public')->delete('leave-requests/attachments/uat-lama.pdf');
        }
    }
}
