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
        // Some commands output multiple JSON objects (e.g. caddy reload + deploy result),
        // so extract the last JSON object if full output fails to parse
        $output = (string) $result['output'];
        $decoded = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE && preg_match_all('/(\{(?:[^{}]|(?1))*\})/s', $output, $matches)) {
            $decoded = json_decode(end($matches[0]), true);
        }

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

    /**
     * Write content to a remote file using base64 encoding to avoid shell escaping issues.
     */
    public function writeFile(Node $node, string $path, string $content): array
    {
        $encoded = base64_encode($content);

        return $this->execute($node, "echo {$encoded} | base64 -d > " . escapeshellarg($path), 30);
    }

    /**
     * Read a file from a remote node.
     */
    public function readFile(Node $node, string $path): ?string
    {
        $result = $this->execute($node, 'cat ' . escapeshellarg($path));

        return $result['success'] ? $result['output'] : null;
    }

    /**
     * Check if a file exists on a remote node.
     */
    public function fileExists(Node $node, string $path): bool
    {
        $result = $this->execute($node, '[ -e ' . escapeshellarg($path) . ' ] && echo yes || echo no');

        return trim($result['output'] ?? '') === 'yes';
    }

    /**
     * Check if a directory exists on a remote node.
     */
    public function directoryExists(Node $node, string $path): bool
    {
        $result = $this->execute($node, '[ -d ' . escapeshellarg($path) . ' ] && echo yes || echo no');

        return trim($result['output'] ?? '') === 'yes';
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
