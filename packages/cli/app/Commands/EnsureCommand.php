<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use App\Services\DockerManager;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class EnsureCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'ensure {--json : Output as JSON}';

    protected $description = 'Ensure all Orbit services are running';

    protected array $requiredServices = [
        'dns',
        'caddy',
        'redis',
        'reverb',
    ];

    public function handle(
        DockerManager $dockerManager,
        ConfigManager $configManager
    ): int {
        $results = [
            'docker' => false,
            'containers' => false,
        ];

        if (! $this->isDockerRunning()) {
            $this->logOrOutput('Docker is not running, skipping...', 'warn');

            return $this->outputResult($results);
        }
        $results['docker'] = true;

        $allStatuses = $dockerManager->getAllStatuses();
        $allRunning = true;

        foreach ($this->requiredServices as $service) {
            if (! isset($allStatuses[$service]) || ! $allStatuses[$service]['running']) {
                $allRunning = false;
                break;
            }
        }

        if (! $allRunning) {
            $this->logOrOutput('Starting containers...', 'info');
            $this->call('start');
            $dockerManager->clearStatusCache();
        }
        $results['containers'] = true;

        return $this->outputResult($results);
    }

    protected function isDockerRunning(): bool
    {
        $result = Process::run('docker info');

        return $result->successful();
    }

    protected function logOrOutput(string $message, string $type): void
    {
        if ($this->wantsJson()) {
            return;
        }

        match ($type) {
            'info' => $this->info($message),
            'warn' => $this->warn($message),
            'error' => $this->error($message),
            default => $this->line($message),
        };
    }

    protected function outputResult(array $results): int
    {
        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'docker' => $results['docker'],
                'containers' => $results['containers'],
                'all_running' => $results['docker'] && $results['containers'],
            ]);
        }

        if ($results['docker'] && $results['containers']) {
            $this->info('All services are running.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
