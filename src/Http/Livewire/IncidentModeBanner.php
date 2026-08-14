<?php

namespace Vigilance\Http\Livewire;

use Livewire\Component;
use Vigilance\Support\IncidentMode;
use Vigilance\Vigilance;

/**
 * The incident-mode control, shown in the dashboard shell.
 *
 * Deliberately always visible (as a button when idle, a banner when engaged):
 * a switch you have to go looking for is a switch nobody finds at 3am, and a
 * banner is also how you avoid the state everyone dreads — full-detail capture
 * quietly left running for a week.
 */
class IncidentModeBanner extends Component
{
    public int $minutes = 30;

    public function engage(): void
    {
        if (! $this->available()) {
            return;
        }

        $result = IncidentMode::engage($this->minutes, Vigilance::currentUser());

        session()->flash('vigilance.flash', [
            'type' => 'success',
            'message' => "Incident mode engaged for {$result['minutes']} minutes. It expires on its own.",
        ]);
    }

    public function disengage(): void
    {
        IncidentMode::disengage();

        session()->flash('vigilance.flash', [
            'type' => 'success',
            'message' => 'Incident mode disengaged.',
        ]);
    }

    public function render()
    {
        return view('vigilance::partials.incident-mode', [
            'available' => $this->available(),
            'active' => IncidentMode::active(),
            'secondsLeft' => IncidentMode::secondsRemaining(),
            'engagedBy' => IncidentMode::state()['by'] ?? null,
            'maxMinutes' => IncidentMode::maxMinutes(),
        ]);
    }

    protected function available(): bool
    {
        return (bool) config('vigilance.incident_mode.enabled', false);
    }
}
