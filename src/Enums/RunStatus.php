<?php

namespace Vigilance\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Released = 'released';
    case Skipped = 'skipped';

    public function isOpen(): bool
    {
        return in_array($this, [self::Queued, self::Running, self::Released], true);
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Skipped], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'slate',
            self::Running => 'blue',
            self::Succeeded => 'emerald',
            self::Failed => 'red',
            self::Released => 'amber',
            self::Skipped => 'zinc',
        };
    }
}
