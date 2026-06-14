<?php

namespace Vigilance\Control\Exceptions;

use RuntimeException;

/**
 * Thrown when a submitted dispatch-form value cannot be coerced into the type
 * required by a job constructor parameter (e.g. a required value is missing).
 */
class InvalidParameter extends RuntimeException {}
