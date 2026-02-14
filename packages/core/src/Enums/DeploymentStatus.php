<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Enums;

enum DeploymentStatus: string
{
    case Pending = 'pending';
    case Deploying = 'deploying';
    case Cloning = 'cloning';
    case SettingUp = 'setting_up';
    case Installing = 'installing';
    case Building = 'building';
    case Active = 'active';
    case Failed = 'failed';
    case Removed = 'removed';

    public function isInProgress(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Deploying,
            self::Cloning,
            self::SettingUp,
            self::Installing,
            self::Building,
        ]);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Active, self::Failed, self::Removed]);
    }
}
