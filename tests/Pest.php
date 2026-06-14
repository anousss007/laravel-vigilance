<?php

use Vigilance\Tests\TestCase;
use Vigilance\Tests\TracingTestCase;

uses(TestCase::class)->in('Feature');

// Boot-dependent tracing tests need the app booted with tracing enabled.
uses(TracingTestCase::class)->in('Tracing');
