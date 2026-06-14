<?php

namespace Vigilance\Control\Exceptions;

use RuntimeException;

/**
 * Thrown when a failed run cannot be retried — typically because the serialized
 * job payload was not stored, is corrupt, or no longer maps to a known class.
 */
class CannotRetry extends RuntimeException {}
