<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Enums;

enum TrackedJobStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
