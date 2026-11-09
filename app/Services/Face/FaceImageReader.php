<?php

namespace App\Services\Face;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads image contents for the face drivers. Looks on the public disk, the default disk,
 * the local disk and finally treats the path as an absolute path.
 */
class FaceImageReader
{
    public function read(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disks = array_unique(['public', (string) config('filesystems.default', 'public'), 'local']);

        foreach ($disks as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    return Storage::disk($disk)->get($path);
                }
            } catch (Throwable) {
                continue;
            }
        }

        if (is_file($path)) {
            $contents = file_get_contents($path);

            return $contents === false ? null : $contents;
        }

        return null;
    }
}
