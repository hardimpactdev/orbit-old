<?php

declare(strict_types=1);

namespace App\Contracts;

interface Component
{
    public function name(): string;

    public function label(): string;

    /**
     * @return class-string|null
     */
    public function prepare(string $osFamily): ?string;

    /**
     * @return array<array{action: class-string, name: string}>
     */
    public function installSteps(string $osFamily): array;

    public function supportsPlatform(string $osFamily): bool;
}
