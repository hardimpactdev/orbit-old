<?php

declare(strict_types=1);

namespace App\Contracts;

interface Template
{
    public function name(): string;

    public function label(): string;

    public function description(): string;

    /**
     * @return array<int, string>
     */
    public function platforms(): array;

    public function supportsPlatform(string $osFamily): bool;

    /**
     * @return array<array{action: class-string, name: string}>
     */
    public function installSteps(string $osFamily): array;
}
