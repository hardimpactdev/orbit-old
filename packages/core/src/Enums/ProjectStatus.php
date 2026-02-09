<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Enums;

enum ProjectStatus: string
{
    case Queued = 'queued';
    case CreatingRepo = 'creating_repo';
    case Cloning = 'cloning';
    case SettingUp = 'setting_up';
    case InstallingComposer = 'installing_composer';
    case InstallingNpm = 'installing_npm';
    case Building = 'building';
    case Finalizing = 'finalizing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Active = 'active';

    public function isProvisioning(): bool
    {
        return in_array($this, [
            self::Queued,
            self::CreatingRepo,
            self::Cloning,
            self::SettingUp,
            self::InstallingComposer,
            self::InstallingNpm,
            self::Building,
            self::Finalizing,
        ], true);
    }
}
