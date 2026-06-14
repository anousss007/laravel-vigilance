<?php

namespace Vigilance\Apm\Recorders;

use Illuminate\Contracts\Config\Repository;
use Vigilance\Apm\Apm;

/**
 * Base for APM recorders. Each recorder captures cheaply on its framework event
 * and defers the heavy work via $this->apm->lazy(). Per-recorder config lives at
 * vigilance.apm.recorders.<class>.
 */
abstract class Recorder
{
    public function __construct(
        protected Apm $apm,
        protected Repository $config,
    ) {}

    protected function recorderConfig(string $key, mixed $default = null): mixed
    {
        return $this->config->get('vigilance.apm.recorders.'.static::class.'.'.$key, $default);
    }
}
