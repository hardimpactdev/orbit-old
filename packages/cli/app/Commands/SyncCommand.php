<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ProjectScanner;
use HardImpact\Orbit\Core\Support\ProjectHelper;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class SyncCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'sync
                            {site : Site/project slug}
                            {--json : Output as JSON}';

    protected $description = 'Sync local project on main (pull + migrate + horizon terminate)';

    public function handle(ProjectScanner $scanner): int
    {
        $site = (string) $this->argument('site');
        $path = $scanner->findProjectPath($site);

        if (! $path) {
            return $this->failWithMessage("Site not found: {$site}. Run: orbit project:scan");
        }

        $path = ProjectHelper::expandPath($path);
        $steps = [];

        $steps['checkout_main'] = $this->runStep($path, 'git checkout main', 60);
        if (! ($steps['checkout_main']['success'] ?? false)) {
            return $this->out($site, $path, false, $steps, 'git checkout main failed');
        }

        $steps['pull'] = $this->runStep($path, 'git pull --ff-only', 120);
        if (! ($steps['pull']['success'] ?? false)) {
            return $this->out($site, $path, false, $steps, 'git pull failed');
        }

        if (file_exists($path.'/artisan')) {
            $steps['migrate'] = $this->runStep($path, 'php artisan migrate --force', 180);
            $steps['optimize_clear'] = $this->runStep($path, 'php artisan optimize:clear', 120);

            if (file_exists($path.'/config/horizon.php')) {
                $steps['horizon_terminate'] = $this->runStep($path, 'php artisan horizon:terminate', 60);
            }
        }

        return $this->out($site, $path, true, $steps);
    }

    private function runStep(string $path, string $cmd, int $timeout): array
    {
        $r = Process::path($path)->timeout($timeout)->run($cmd);

        return [
            'success' => $r->successful(),
            'command' => $cmd,
            'output' => trim($r->output()),
            'error' => trim($r->errorOutput()),
        ];
    }

    private function out(string $site, string $path, bool $success, array $steps, ?string $error = null): int
    {
        $payload = [
            'success' => $success,
            'site' => $site,
            'path' => $path,
            'steps' => $steps,
        ];

        if ($error !== null) {
            $payload['error'] = $error;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($payload, $success ? self::SUCCESS : self::FAILURE);
        }

        if (! $success) {
            $this->error((string) ($payload['error'] ?? 'sync failed'));
            return self::FAILURE;
        }

        $this->info('Sync complete: '.$site);
        return self::SUCCESS;
    }

    private function failWithMessage(string $message): int
    {
        if ($this->wantsJson()) {
            return $this->outputJson(['success' => false, 'error' => $message], self::FAILURE);
        }
        $this->error($message);

        return self::FAILURE;
    }
}
