<?php

namespace Vigilance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * A telemetry rule created from the dashboard rather than the config file.
 *
 * Config is the right home for the rules you decide up front. It is the wrong
 * home for the ones you only discover in production: seeing a route generate
 * 40% of your telemetry and then having to edit a file and redeploy is why
 * that route is still generating 40% of your telemetry three weeks later.
 *
 * @property int $id
 * @property string $scope
 * @property string $pattern
 * @property string $action
 * @property ?string $replacement
 * @property ?string $created_by
 * @property ?string $note
 * @property ?Carbon $expires_at
 */
class Suppression extends VigilanceModel
{
    public const SCOPE_ROUTE = 'route';

    public const SCOPE_QUERY = 'query';

    public const SCOPE_CACHE_KEY = 'cache_key';

    public const SCOPE_EXCEPTION = 'exception';

    protected $table = 'vigilance_suppressions';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @return list<string> */
    public static function scopes(): array
    {
        return [self::SCOPE_ROUTE, self::SCOPE_QUERY, self::SCOPE_CACHE_KEY, self::SCOPE_EXCEPTION];
    }
}
