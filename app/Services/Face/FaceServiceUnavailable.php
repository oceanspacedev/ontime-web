<?php

namespace App\Services\Face;

use RuntimeException;

/**
 * The face service cannot answer right now (not configured, unreachable, rejected the key or failed).
 */
class FaceServiceUnavailable extends RuntimeException {}
