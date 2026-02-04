<?php

declare(strict_types=1);

namespace App\Components;

use App\Actions\Install\Mac;
use App\Actions\Prepare\Linux as PrepareLinux;
use App\Actions\Prepare\Mac as PrepareMac;
use App\Contracts\Component;

final readonly class PhpComponent implements Component
{
    public function name(): string
    {
        return 'php';
    }

    public function label(): string
    {
        return 'PHP-FPM';
    }

    public function prepare(string $osFamily): ?string
    {
        return match ($osFamily) {
            'Darwin' => PrepareMac\PreparePhp::class,
            'Linux' => PrepareLinux\PreparePhp::class,
            default => null,
        };
    }

    public function installSteps(string $osFamily): array
    {
        // Use Homebrew for PHP on both macOS and Linux
        return match ($osFamily) {
            'Darwin', 'Linux' => [
                ['action' => Mac\ConfigurePhpFpm::class, 'name' => 'Configuring PHP-FPM'],
            ],
            default => [],
        };
    }

    public function supportsPlatform(string $osFamily): bool
    {
        return in_array($osFamily, ['Darwin', 'Linux'], true);
    }
}
