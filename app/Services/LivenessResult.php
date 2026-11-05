<?php

namespace App\Services;

use App\Services\Face\FaceMatch;

final readonly class LivenessResult
{
    /**
     * @param  bool  $livenessPassed  The challenge was valid and every pose was correct.
     * @param  string  $notes  Indonesian, shown to the user.
     * @param  string|null  $frontFramePath  The "hadap depan" frame, used as the attendance photo. Null when liveness failed.
     * @param  FaceMatch|null  $faceMatch  Match of the front frame with the master face photo. Null when liveness failed before matching.
     */
    public function __construct(
        public bool $livenessPassed,
        public string $notes,
        public ?string $frontFramePath,
        public ?FaceMatch $faceMatch,
    ) {}

    public static function failed(string $notes): self
    {
        return new self(false, $notes, null, null);
    }
}
