<?php

namespace Vigilance\Http\Livewire\Concerns;

use Illuminate\Support\Collection;
use Vigilance\Models\Suppression;
use Vigilance\Support\Suppressions;
use Vigilance\Vigilance;

/**
 * Lets a page turn the finding it is displaying into a rule, in place.
 *
 * Vigilance has always been good at surfacing the noisy route, the chatty cache
 * key and the spammy exception, and then leaving you to go edit
 * config/vigilance.php and redeploy. This closes that loop: the button sits next
 * to the thing it is about, the rule takes effect immediately, and it carries an
 * expiry so "mute while we ship the fix" does not quietly become forever.
 */
trait CreatesSuppressions
{
    /** Minutes before a new rule expires; 0 keeps it until removed. */
    public int $suppressFor = 0;

    public function suppress(string $scope, string $pattern): void
    {
        if (! in_array($scope, Suppression::scopes(), true) || trim($pattern) === '') {
            return;
        }

        Suppression::query()->updateOrCreate(
            ['scope' => $scope, 'pattern' => $pattern],
            [
                'action' => 'ignore',
                'created_by' => Vigilance::currentUser(),
                'expires_at' => $this->suppressFor > 0 ? now()->addMinutes($this->suppressFor) : null,
            ],
        );

        Suppressions::forget();

        session()->flash('vigilance.flash', [
            'type' => 'success',
            'message' => 'Rule created — new telemetry matching it stops being recorded.'
                .($this->suppressFor > 0 ? " Expires in {$this->suppressFor} minutes." : ''),
        ]);
    }

    public function unsuppress(int $id): void
    {
        Suppression::query()->whereKey($id)->delete();

        Suppressions::forget();

        session()->flash('vigilance.flash', [
            'type' => 'success',
            'message' => 'Rule removed.',
        ]);
    }

    /**
     * The rules currently in force for a scope, so a page can show what it is
     * already hiding — a filter you cannot see is a filter you will forget.
     *
     * @return Collection<int, Suppression>
     */
    public function activeSuppressions(string $scope)
    {
        return Suppression::query()->active()->where('scope', $scope)->orderBy('pattern')->get();
    }
}
