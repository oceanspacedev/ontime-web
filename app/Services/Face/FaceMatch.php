<?php

namespace App\Services\Face;

final readonly class FaceMatch
{
    /** The worker's master face file is gone from storage; only registering it again helps. */
    public const MASTER_MISSING = 'Foto profil tidak ditemukan di server. Pasang ulang foto profil Anda di menu Profil.';

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
