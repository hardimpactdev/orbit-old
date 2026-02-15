<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Support\Facades\Process;

class SshService
{
    // Use /tmp for control sockets to avoid path length issues
    protected string $controlDir = '/tmp/orbit-ssh';

    protected int $controlPersist = 600;

    public function __construct()
    {
        if (! is_dir($this->controlDir)) {
            mkdir($this->controlDir, 0700, true);
        }
    }

    protected function getControlPath(Node $node): string
    {
        // Use short hash to avoid path length limits on macOS
        $hash = substr(md5("{$node->user}@{$node->host}:{$node->port}"), 0, 12);

        return "{$this->controlDir}/ctrl-{$hash}";
    }

    public function testConnection(Node $node): array
    {
        if ($node->isLocal()) {
            return [
                'success' => true,
                'message' => 'Local connection',
            ];
        }

        $result = Process::timeout(10)->run($this->buildSshCommand($node, 'echo "connected"'));

        return [
            'success' => $result->successful(),
            'message' => $result->successful() ? 'Connected successfully' : $result->errorOutput(),
            'output' => $result->output(),
        ];
    }

    public function execute(Node $node, string $command, int $timeout = 30): array
    {
        if ($node->isLocal()) {
            $result = Process::timeout($timeout)->run($command);
        } else {
            // Prepend common paths for PHP and other binaries
            $pathPrefix = 'export PATH="$HOME/.local/bin:$HOME/.bun/bin:$HOME/bin:/usr/local/bin:$PATH" && ';
            $result = Process::timeout($timeout)->run($this->buildSshCommand($node, $pathPrefix.$command));
        }

        return [
            'success' => $result->successful(),
            'exit_code' => $result->exitCode(),
            'output' => $result->output(),
            'error' => $result->errorOutput() ?: $result->output() ?: 'SSH command failed',
        ];
    }

    public function executeJson(Node $node, string $command): array
    {
        $result = $this->execute($node, $command);

        // Always try to parse JSON from stdout, even if command failed
        // CLI tools often return valid JSON with error info even on non-zero exit
        $decoded = json_decode((string) $result['output'], true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Successfully parsed JSON - return the decoded data
            // The CLI's own success/error fields will be in $decoded
            return [
                'success' => true,
                'exit_code' => $result['exit_code'],
                'data' => $decoded,
            ];
        }

        // JSON parsing failed - return the raw result
        if (! $result['success']) {
            return $result;
        }

        return [
            'success' => false,
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
            'error' => 'Failed to parse JSON: '.json_last_error_msg(),
        ];
    }

    protected function buildSshCommand(Node $node, string $command): string
    {
        $controlPath = $this->getControlPath($node);

        $sshOptions = [
            '-o BatchMode=yes',
            '-o StrictHostKeyChecking=accept-new',
            '-o ConnectTimeout=10',
            "-o ControlPath={$controlPath}",
            '-o ControlMaster=auto',
            "-o ControlPersist={$this->controlPersist}",
        ];

        if ($node->port !== 22) {
            $sshOptions[] = "-p {$node->port}";
        }

        $options = implode(' ', $sshOptions);
        $escapedCommand = escapeshellarg($command);

        return "ssh {$options} {$node->user}@{$node->host} {$escapedCommand}";
    }

    public function closeConnection(Node $node): void
    {
        if ($node->isLocal()) {
            return;
        }

        $controlPath = $this->getControlPath($node);
        Process::run("ssh -O exit -o ControlPath={$controlPath} {$node->user}@{$node->host}");
    }
}
