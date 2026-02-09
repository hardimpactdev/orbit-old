<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Enums;

enum NodeType: string
{
    case Local = 'local';
    case Gateway = 'gateway';
    case Client = 'client';
}
