<?php

namespace App\Services\Face;

/**
 * A face service driver. Paths are storage paths (public, default or local disk) or absolute paths.
 */
interface FaceVerifier
{
    /**
     * The driver name: 'basic' or 'compreface'.
     */
    public function name(): string;

    /**
     * Whether detect() returns the head pose, which the liveness challenge needs.
     */
    public function supportsPose(): bool;

    /**
     * Whether the driver does real face recognition. False for the built-in pixel comparison.
     */
    public function isAccurate(): bool;

    /**
     * Compare a selfie with the registered master face photo.
     *
     * @throws FaceServiceUnavailable
     */
    public function verify(string $selfiePath, string $masterPath, int $thresholdPercent): FaceMatch;

    /**
     * Count the faces in an image and read the head pose of the first one.
     *
     * @throws FaceServiceUnavailable
     */
    public function detect(string $imagePath): FaceDetection;
}
