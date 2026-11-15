<?php

namespace App\Services\Face;

final readonly class FaceMatch
{
    /** The worker's master face file is gone from storage; only registering it again helps. */
    public const MASTER_MISSING = 'Foto Master Wajah tidak ditemukan di server. Daftarkan ulang Foto Master Wajah Anda.';

    /**
     * @param  int  $score  Similarity from 0 to 100.
     * @param  string  $notes  Indonesian explanation shown to the user and stored on the attendance.
     */
    public function __construct(
        public bool $matched,
        public int $score,
        public string $notes,
    ) {}
}
