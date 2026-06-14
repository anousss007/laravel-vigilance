<?php

namespace Vigilance\Contracts;

/**
 * Marker interface opting a job in to manual dispatch from the dashboard when
 * control.jobs.mode is "marker". The job's public constructor signature is
 * reflected to build the dispatch form.
 *
 * Optionally declare:
 *   public static string $vigilanceLabel = 'Human friendly name';
 *   public static string $vigilanceDescription = 'What this job does';
 */
interface Dispatchable {}
