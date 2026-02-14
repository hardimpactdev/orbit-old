<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Enums;

enum NodeEnvironment: string
{
    case Development = 'development';
    case Staging = 'staging';
    case Production = 'production';
}
