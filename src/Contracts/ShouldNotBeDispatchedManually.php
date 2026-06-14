<?php

namespace Vigilance\Contracts;

/**
 * Jobs implementing this interface are hidden from the dashboard's manual
 * dispatch surface, even when control.jobs.mode is "discover" (which otherwise
 * exposes every queued job). Use it to keep jobs with real side effects — or
 * constructors that can't be filled from a form — out of the control plane.
 *
 * This is the dispatch-side counterpart to ShouldNotBeMonitored.
 */
interface ShouldNotBeDispatchedManually {}
