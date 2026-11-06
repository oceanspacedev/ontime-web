<?php

namespace App\Services\Face;

final readonly class FaceMatch
{
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
