<?php

namespace App\Services\Face;

final readonly class FaceDetection
{
    /**
     * @param  int  $faces  Number of faces found in the image.
     * @param  float|null  $yaw  Head pose of the first face in degrees, null when unknown.
     */
    public function __construct(
        public int $faces,
        public ?float $yaw = null,
        public ?float $pitch = null,
        public ?float $roll = null,
    ) {}

    public function hasPose(): bool
    {
        return $this->yaw !== null && $this->pitch !== null;
    }
}
