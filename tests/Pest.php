<?php

use Vigilance\Tests\LogsTestCase;
use Vigilance\Tests\McpTestCase;
use Vigilance\Tests\TestCase;
use Vigilance\Tests\TracingTestCase;

uses(TestCase::class)->in('Feature');

// Boot-dependent tracing tests need the app booted with tracing enabled.
uses(TracingTestCase::class)->in('Tracing');

// Boot-dependent log-capture tests need the app booted with log capture on.
uses(LogsTestCase::class)->in('Logs');

// MCP-server tests boot laravel/mcp alongside Vigilance.
uses(McpTestCase::class)->in('Mcp');
