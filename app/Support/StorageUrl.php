<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Links to stored files (selfies, face photos, attachments) that open on any disk.
 *
 * A private disk such as "local" (serve: true) or s3 gets a signed temporary URL; a public disk gets
 * its plain URL. Building "/storage/..." by hand only works on the public disk with storage:link, so
 * it broke every photo link while FILESYSTEM_DISK=local.
 */
final class StorageUrl
{
    public static function for(?string $path, ?string $disk = null, int $validForDays = 7): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $storage = Storage::disk($disk ?? config('filesystems.default'));

        if ($storage->providesTemporaryUrls()) {
            try {
                return $storage->temporaryUrl($path, now()->addDays($validForDays));
            } catch (Throwable) {
                // Fall back to the plain URL below.
            }
        }

        return $storage->url($path);
    }
}
