<?php

namespace Vigilance\Models;

use Illuminate\Database\Eloquent\Model;

abstract class VigilanceModel extends Model
{
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('vigilance.storage.connection') ?: parent::getConnectionName();
    }
}
