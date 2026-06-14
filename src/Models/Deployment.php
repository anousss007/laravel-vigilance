<?php

namespace Vigilance\Models;

use Illuminate\Support\Carbon;

/**
 * A deployment marker — used to correlate a change in metrics or error rate
 * with the release that caused it.
 *
 * @property int $id
 * @property ?string $version
 * @property ?string $commit
 * @property ?string $environment
 * @property ?string $notes
 * @property Carbon $deployed_at
 * @property ?Carbon $created_at
 */
class Deployment extends VigilanceModel
{
    protected $table = 'vigilance_deployments';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'deployed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function shortCommit(): ?string
    {
        return $this->commit !== null ? substr($this->commit, 0, 8) : null;
    }

    public function label(): string
    {
        return $this->version ?: ($this->shortCommit() ?: 'deployment #'.$this->id);
    }
}
