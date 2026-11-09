<?php

namespace App\Support;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Links to stored files (selfies, face photos, attachments) that open in the browser on any disk.
 *
 * A private disk such as "local" (serve: true) or s3 gets a signed temporary URL; a public disk gets
 * its plain URL. Building "/storage/..." by hand only works on the public disk with storage:link, so
 * it broke every photo link while FILESYSTEM_DISK=local.
 */
final class StorageUrl
{
    /**
     * Files saved before uploads followed the default disk may still sit on the "public" disk; they
     * keep their link there when no disk is given.
     */
    public static function for(?string $path, ?string $disk = null, int $validForDays = 7): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $disk ??= self::diskHolding($path);
        $storage = Storage::disk($disk);

        if ($storage->providesTemporaryUrls()) {
            try {
                return $storage->temporaryUrl($path, now()->addDays($validForDays), self::inlineOptions($storage, $path));
            } catch (Throwable) {
                // Fall back to the plain URL below.
            }
        }

        return $storage->url($path);
    }

    /**
     * Serve files of a private local disk inline. Laravel's own headers sandbox every file, which
     * makes Chrome refuse to show a PDF, so PDFs go out without the sandbox.
     */
    public static function serveInline(FilesystemAdapter $storage): void
    {
        $storage->serveUsing(function (Request $request, string $path, array $headers) use ($storage): StreamedResponse {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf') {
                $headers['Content-Security-Policy'] = "frame-ancestors 'self'";
            }

            return $storage->response($path, headers: $headers);
        });
    }

    private static function diskHolding(string $path): string
    {
        $default = (string) config('filesystems.default');

        if ($default !== 'public' && Storage::disk('public')->exists($path)) {
            return 'public';
        }

        return $default;
    }

    /**
     * S3 sends a file as a download when its stored type is generic, so the signed link asks for
     * the type that matches the extension and an inline disposition.
     *
     * @return array<string, string>
     */
    private static function inlineOptions(FilesystemAdapter $storage, string $path): array
    {
        if (! $storage instanceof AwsS3V3Adapter) {
            return [];
        }

        $options = ['ResponseContentDisposition' => 'inline'];

        if ($mimeType = (new ExtensionMimeTypeDetector)->detectMimeTypeFromPath($path)) {
            $options['ResponseContentType'] = $mimeType;
        }

        return $options;
    }
}
