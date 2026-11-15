<?php

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * An uploaded file the server could not write to its storage disk (S3 or local).
 *
 * The API answers 503 with an Indonesian message, so the app keeps the photo and lets the worker
 * send it again instead of failing with an unexplained server error.
 */
final class UploadStorageException extends RuntimeException
{
    public function __construct(string $message = 'Berkas gagal disimpan di server. Coba kirim lagi beberapa saat.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
