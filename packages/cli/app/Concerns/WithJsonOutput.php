<?php

declare(strict_types=1);

namespace App\Concerns;

trait WithJsonOutput
{
    protected function wantsJson(): bool
    {
        return (bool) $this->option('json') || ! $this->input->isInteractive();
    }

    protected function outputJson(array $data, int $exitCode = self::SUCCESS): int
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $exitCode;
    }

    protected function outputJsonSuccess(array $data): int
    {
        return $this->outputJson([
            'success' => true,
            'data' => $data,
        ], self::SUCCESS);
    }

    protected function outputJsonError(string $message, int $exitCode = self::FAILURE, array $extra = []): int
    {
        return $this->outputJson(array_merge([
            'success' => false,
            'error' => $message,
        ], $extra), $exitCode);
    }

    protected function failWithMessage(string $message): int
    {
        if ($this->wantsJson()) {
            return $this->outputJsonError($message);
        }

        $this->error($message);

        return self::FAILURE;
    }
}
