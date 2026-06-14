<?php

namespace Vigilance\Contracts;

/**
 * Jobs implementing this interface are never recorded by Vigilance, even when
 * monitoring is otherwise enabled. Useful for extremely high-throughput or
 * sensitive jobs.
 */
interface ShouldNotBeMonitored {}
