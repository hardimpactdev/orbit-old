<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use LaravelZero\Framework\Commands\Command;

final class SchemaCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'schema {target? : Command name (e.g. worktree:setup)} {--json : Output as JSON}';

    protected $description = 'Show machine-readable command schema for agent-safe usage';

    public function handle(): int
    {
        $schemas = [
            'sync' => [
                'args' => ['site'],
                'options' => ['--json', '--dry-run'],
                'mutates' => true,
                'outputs' => ['success', 'site', 'path', 'steps', 'error?'],
            ],
            'worktree:setup' => [
                'args' => ['site', 'worktree'],
                'options' => ['--branch', '--base', '--force', '--json', '--dry-run'],
                'mutates' => true,
                'outputs' => ['success', 'site', 'worktree', 'worktree_path', 'domain', 'branch', 'changed', 'steps', 'error?'],
            ],
            'worktree:cleanup' => [
                'args' => ['site', 'worktree'],
                'options' => ['--delete-branch', '--delete-remote', '--json', '--dry-run'],
                'mutates' => true,
                'outputs' => ['success', 'site', 'worktree', 'worktree_path', 'steps', 'error?'],
            ],
        ];

        $command = (string) ($this->argument('target') ?? '');

        $payload = $command === ''
            ? ['success' => true, 'commands' => $schemas]
            : (isset($schemas[$command])
                ? ['success' => true, 'command' => $command, 'schema' => $schemas[$command]]
                : ['success' => false, 'error' => "Unknown schema command: {$command}"]);

        if ($this->wantsJson()) {
            return $this->outputJson($payload, ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE);
        }

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
