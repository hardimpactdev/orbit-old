<?php

declare(strict_types=1);

namespace App\Services\Install;

use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\spin;

final class InstallLogger
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private array $buffer = [];

    private bool $buffering = false;

    public function __construct(
        private readonly Command $command,
    ) {}

    public function title(string $message): void
    {
        $this->flushBuffer();
        $this->command->newLine();
        $this->command->line("<fg=blue;options=bold>{$message}</>");
        $this->command->newLine();
    }

    public function step(string $message): void
    {
        if ($this->buffering) {
            $this->buffer[] = ['step', $message];
        } else {
            $this->command->line("  <fg=yellow>→</> {$message}");
        }
    }

    /**
     * Execute a callback with a spinner for visual feedback.
     * Buffers log output during execution and displays after completion.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function spinner(string $message, callable $callback): mixed
    {
        $this->buffering = true;
        $this->buffer = [];

        $result = spin(fn () => $callback(), $message);

        $this->buffering = false;

        $this->flushBuffer();

        return $result;
    }

    /**
     * Flush buffered messages to output.
     */
    private function flushBuffer(): void
    {
        foreach ($this->buffer as $entry) {
            $type = $entry[0];
            $message = $entry[1];
            match ($type) {
                'step' => null,
                'success' => $this->command->line("  <fg=green>✓</> {$message}"),
                'skip' => $this->command->line("  <fg=gray>○</> {$message}"),
                'error' => $this->command->line("  <fg=red>✗</> {$message}"),
                'warn' => $this->command->line("  <fg=yellow>⚠</> {$message}"),
                'info' => $this->command->line("    {$message}"),
                default => $this->command->line("    {$message}"),
            };
        }
        $this->buffer = [];
    }

    public function success(string $message): void
    {
        if ($this->buffering) {
            $this->buffer[] = ['success', $message];
        } else {
            $this->command->line("  <fg=green>✓</> {$message}");
        }
    }

    public function skip(string $message): void
    {
        if ($this->buffering) {
            $this->buffer[] = ['skip', $message];
        } else {
            $this->command->line("  <fg=gray>○</> {$message}");
        }
    }

    public function error(string $message): void
    {
        if ($this->buffering) {
            $this->buffer[] = ['error', $message];
        } else {
            $this->command->line("  <fg=red>✗</> {$message}");
        }
    }

    public function info(string $message): void
    {
        if ($this->buffering) {
            $this->buffer[] = ['info', $message];
        } else {
            $this->command->line("  {$message}");
        }
    }

    public function warn(string $message): void
    {
        if ($this->buffering) {
            $this->buffer[] = ['warn', $message];
        } else {
            $this->command->line("  <fg=yellow>⚠</> {$message}");
        }
    }

    public function newLine(): void
    {
        if (! $this->buffering) {
            $this->command->newLine();
        }
    }
}
