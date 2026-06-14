<?php

namespace Vigilance\Enums;

enum RunType: string
{
    case Job = 'job';
    case Command = 'command';
    case Schedule = 'schedule';

    public function label(): string
    {
        return match ($this) {
            self::Job => 'Job',
            self::Command => 'Command',
            self::Schedule => 'Scheduled task',
        };
    }
}
