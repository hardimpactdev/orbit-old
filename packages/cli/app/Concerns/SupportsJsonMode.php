<?php

declare(strict_types=1);

namespace App\Concerns;

trait SupportsJsonMode
{
    /**
     * Call a command silently when parent command is in JSON mode.
     * Prevents sub-command output from polluting JSON stream.
     */
    protected function callSilentlyWhenJson(string $command, array $arguments = []): int
    {
        if ($this->option('json')) {
            // Buffer and discard sub-command output
            ob_start();
            $exitCode = $this->call($command, $arguments);
            ob_end_clean();

            return $exitCode;
        }

        // Normal execution when not in JSON mode
        return $this->call($command, $arguments);
    }
}
