<?php

namespace Vigilance\Supervision;

use Illuminate\Support\Str;

/**
 * Turns the `vigilance.environments` + `vigilance.defaults` config into a set
 * of SupervisorOptions for the active environment (supporting wildcard
 * environment names, e.g. "production*").
 */
class ProvisioningPlan
{
    /**
     * @param  array<string, array<string, array<string, mixed>>>  $environments
     * @param  array<string, mixed>  $defaults
     */
    public function __construct(
        protected array $environments,
        protected array $defaults,
    ) {}

    public static function get(): self
    {
        return new self(
            (array) config('vigilance.environments', []),
            (array) config('vigilance.defaults', []),
        );
    }

    /**
     * @return array<string, SupervisorOptions>
     */
    public function toSupervisorOptions(?string $environment = null): array
    {
        $environment ??= app()->environment();

        $options = [];

        foreach ($this->forEnvironment($environment) as $name => $supervisor) {
            $merged = array_replace_recursive($this->defaults, $supervisor);
            $merged['name'] = $name;

            $options[$name] = SupervisorOptions::fromArray($merged);
        }

        return $options;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function forEnvironment(string $environment): array
    {
        if (isset($this->environments[$environment])) {
            return $this->environments[$environment];
        }

        foreach ($this->environments as $pattern => $plan) {
            if (Str::is($pattern, $environment)) {
                return $plan;
            }
        }

        return [];
    }
}
