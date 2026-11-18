<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The QR code cannot be drawn as PNG (no GD extension). Fail closed, never SVG.
 */
class QrUnavailableException extends RuntimeException {}
