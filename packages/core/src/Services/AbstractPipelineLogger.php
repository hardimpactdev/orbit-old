<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
use Illuminate\Support\Facades\Log;

abstract class AbstractPipelineLogger implements ProvisionLoggerContract
{
    private ?string $logFile = null;

    public function __construct(
        protected readonly string $slug,
        protected readonly ?int $projectId = null,
    ) {
        $this->initializeLogFile();
    }

    abstract protected function getLogSubdirectory(): string;

    abstract protected function createBroadcastEvent(string $status, ?string $error): object;

    private function initializeLogFile(): void
    {
        $home = $_SERVER['HOME'] ?? config('orbit.home_directory');
        $logsDir = "{$home}/.config/orbit/logs/{$this->getLogSubdirectory()}";

        if (! is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }

        $this->logFile = "{$logsDir}/{$this->slug}.log";

        @file_put_contents($this->logFile, '');
    }

    public function info(string $message): void
    {
        $this->log($message);
        Log::info("[{$this->slug}] {$message}");
    }

    public function warn(string $message): void
    {
        $this->log("WARNING: {$message}");
        Log::warning("[{$this->slug}] {$message}");
    }

    public function error(string $message): void
    {
        $this->log("ERROR: {$message}");
        Log::error("[{$this->slug}] {$message}");
    }

    public function log(string $message): void
    {
        if ($this->logFile) {
            $timestamp = date('Y-m-d H:i:s');
            @file_put_contents(
                $this->logFile,
                "[{$timestamp}] {$message}\n",
                FILE_APPEND
            );
        }
    }

    public function broadcast(string $status, ?string $error = null): void
    {
        $errorSuffix = $error ? " - Error: {$error}" : '';
        $this->log("Status: {$status}{$errorSuffix}");

        Log::info("Project {$this->slug}: {$status}", [
            'project_id' => $this->projectId,
            'error' => $error,
        ]);

        try {
            event($this->createBroadcastEvent($status, $error));
        } catch (\Throwable $e) {
            Log::warning("Failed to broadcast status for {$this->slug}: {$e->getMessage()}");
        }
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getProjectId(): ?int
    {
        return $this->projectId;
    }
}
