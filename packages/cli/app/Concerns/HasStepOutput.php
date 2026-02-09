<?php

declare(strict_types=1);

namespace App\Concerns;

trait HasStepOutput
{
    private function step(string $message): void
    {
        $this->line("  <fg=green>✓</> {$message}");
    }

    private function skip(string $message): void
    {
        $this->line("  <fg=gray>○</> {$message}");
    }
}
