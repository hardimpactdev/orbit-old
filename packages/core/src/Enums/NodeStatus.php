<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Enums;

enum NodeStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Error = 'error';
}
