<?php

namespace Vigilance\Control\Exceptions;

use RuntimeException;

/**
 * Thrown when a job or command is dispatched/run from the dashboard but is not
 * permitted by the configured control allowlist (or is explicitly denied).
 */
class NotAllowed extends RuntimeException {}
