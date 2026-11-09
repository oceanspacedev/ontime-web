<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class FileNamingService
{
    /**
     * Generate an auditable and structured file name.
     * Format: {PREFIX}_{IDENTIFIER}_{YYYYMMDD_HIS}_{SLUG}_{RANDOM}.{EXT}
     */
    public static function generateFileName(
        string $prefix,
        ?string $identifier,
        UploadedFile|TemporaryUploadedFile|string $file
    ): string {
        $cleanPrefix = strtoupper(Str::slug($prefix, '_'));
        $cleanIdentifier = $identifier ? strtoupper(Str::slug($identifier, '_')) : 'UNIDENTIFIED';
        $timestamp = now()->format('Ymd_His');
        $random = strtolower(Str::random(4));

        if ($file instanceof UploadedFile || $file instanceof TemporaryUploadedFile) {
            $originalName = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        } else {
            $originalName = pathinfo($file, PATHINFO_FILENAME);
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION) ?: 'bin');
        }

        $filenameWithoutExt = pathinfo($originalName, PATHINFO_FILENAME);
        $slug = Str::slug($filenameWithoutExt);
        $slugPart = $slug ? substr($slug, 0, 30) : 'file';

        return "{$cleanPrefix}_{$cleanIdentifier}_{$timestamp}_{$slugPart}_{$random}.{$extension}";
    }

    /**
     * Store an uploaded file with auditable naming.
     */
    public static function storeUploadedFile(
        UploadedFile $file,
        string $directory,
        string $prefix,
        ?string $identifier,
        string $disk = 'public'
    ): string {
        $filename = static::generateFileName($prefix, $identifier, $file);

        return $file->storeAs($directory, $filename, ['disk' => $disk]);
    }
}
