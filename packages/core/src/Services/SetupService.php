<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Models\Environment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/**
 * Service to detect and run first-run setup.
 */
class SetupService
{
    protected array $steps = [
        'check_prerequisites' => 'Checking prerequisites',
        'download_cli' => 'Downloading Orbit CLI',
        'install_cli' => 'Installing CLI',
        'init_services' => 'Installing & configuring services',
        'configure_tld' => 'Finalizing TLD',
        'create_environment' => 'Creating local environment',
    ];

    public function __construct(
        protected CliUpdateService $cliUpdate,
    ) {}

    /**
     * Check if first-run setup is needed.
     */
    public function needsSetup(): bool
    {
        // Check if CLI is installed
        if (! $this->isCliInstalled()) {
            return true;
        }

        // Check if local environment exists
        if (! $this->hasLocalEnvironment()) {
            return true;
        }

        // Check if services are configured
        if (! $this->hasServicesConfigured()) {
            return true;
        }

        return false;
    }

    /**
     * Get the current setup status for display.
     */
    public function getStatus(): array
    {
        $cliStatus = $this->cliUpdate->getStatus();

        return [
            'needs_setup' => $this->needsSetup(),
            'cli_installed' => $cliStatus['installed'],
            'cli_version' => $cliStatus['version'],
            'has_local_environment' => $this->hasLocalEnvironment(),
            'has_services' => $this->hasServicesConfigured(),
            'has_tld' => $this->hasTldConfigured(),
            'steps' => $this->steps,
        ];
    }

    /**
     * Check if CLI is installed.
     */
    public function isCliInstalled(): bool
    {
        return $this->cliUpdate->isInstalled();
    }

    /**
     * Check if local environment exists.
     */
    public function hasLocalEnvironment(): bool
    {
        return Environment::where('is_local', true)->exists();
    }

    /**
     * Check if services are configured (Docker containers running).
     */
    public function hasServicesConfigured(): bool
    {
        // Check if orbit services are running via CLI
        $cliPath = $this->cliUpdate->getPharPath();

        if (! file_exists($cliPath)) {
            return false;
        }

        $phpBinary = PHP_BINARY;
        $result = Process::timeout(10)
            ->env(['PATH' => $this->getSafePath()])
            ->run("{$phpBinary} {$cliPath} status --json 2>/dev/null");

        if (! $result->successful()) {
            return false;
        }

        $output = trim($result->output());
        if (empty($output)) {
            return false;
        }

        $data = json_decode($output, true);

        // Check if services array exists and has running containers
        // Note: CLI output wraps everything in 'data' key, but sometimes it might be direct
        $services = $data['data']['services'] ?? [];
        if (empty($services)) {
            return false;
        }

        // Check if at least core services are running
        $runningCount = collect($services)
            ->filter(fn ($s): bool => ($s['status'] ?? '') === 'running')
            ->count();

        return $runningCount >= 3; // At least 3 core services (postgres, redis, etc.)
    }

    /**
     * Check if TLD is configured.
     */
    public function hasTldConfigured(): bool
    {
        $env = Environment::getLocal();

        return $env !== null && ! empty($env->tld);
    }

    /**
     * Run the complete setup flow.
     * Returns a generator for progress tracking.
     *
     * @return \Generator<string, array{step: int, total: int, message: string, success: bool, error?: string|null}>
     */
    public function runSetup(string $tld = 'test'): \Generator
    {
        $totalSteps = count($this->steps);
        $currentStep = 0;

        // Step 1: Check prerequisites
        $currentStep++;
        yield 'check_prerequisites' => [
            'step' => $currentStep,
            'total' => $totalSteps,
            'message' => $this->steps['check_prerequisites'],
            'success' => true,
        ];

        // Step 2: Download CLI
        $currentStep++;
        yield 'download_cli' => [
            'step' => $currentStep,
            'total' => $totalSteps,
            'message' => $this->steps['download_cli'],
            'success' => true,
        ];

        // Step 3: Install CLI
        $currentStep++;
        $cliResult = $this->cliUpdate->ensureInstalled();
        yield 'install_cli' => [
            'step' => $currentStep,
            'total' => $totalSteps,
            'message' => $this->steps['install_cli'],
            'success' => $cliResult['success'],
            'error' => $cliResult['error'] ?? null,
        ];

        if (! $cliResult['success']) {
            return;
        }

        // Step 4: Initialize services (includes TLD configuration)
        $currentStep++;
        $initResult = $this->initServices($tld);
        yield 'init_services' => [
            'step' => $currentStep,
            'total' => $totalSteps,
            'message' => $this->steps['init_services'],
            'success' => $initResult['success'],
            'error' => $initResult['error'] ?? null,
        ];

        if (! $initResult['success']) {
            return;
        }

        // Step 5: Configure TLD
        $currentStep++;
        $tldResult = $this->configureTld($tld);
        yield 'configure_tld' => [
            'step' => $currentStep,
            'total' => $totalSteps,
            'message' => $this->steps['configure_tld'],
            'success' => $tldResult['success'],
            'error' => $tldResult['error'] ?? null,
        ];

        // Step 6: Create local environment
        $currentStep++;
        $envResult = $this->createLocalEnvironment($tld);
        yield 'create_environment' => [
            'step' => $currentStep,
            'total' => $totalSteps,
            'message' => $this->steps['create_environment'],
            'success' => $envResult['success'],
            'error' => $envResult['error'] ?? null,
        ];
    }

    /**
     * Ensure the database is ready (file exists, migrations run).
     * This is critical for the Desktop App flow where the app owns the database.
     */
    protected function ensureDatabaseReady(): array
    {
        try {
            // Ensure the config directory exists
            $configPath = config('orbit.config_path', getenv('HOME') . '/.config/orbit');
            if (! File::isDirectory($configPath)) {
                File::makeDirectory($configPath, 0755, true);
            }

            // Ensure database file exists
            $dbPath = config('database.connections.sqlite.database');
            if ($dbPath && ! File::exists($dbPath)) {
                File::put($dbPath, '');
            }

            // Check if migrations have run by checking for a core table
            if (! Schema::hasTable('environments')) {
                // Run migrations
                Artisan::call('migrate', ['--force' => true]);
            }

            return ['success' => true];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to initialize database: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Initialize orbit services via CLI.
     */
    protected function initServices(string $tld = 'test'): array
    {
        // First ensure database is ready (Desktop App owns the database)
        $dbResult = $this->ensureDatabaseReady();
        if (! $dbResult['success']) {
            return $dbResult;
        }

        $cliPath = $this->cliUpdate->getPharPath();
        $phpBinary = PHP_BINARY;

        // Run orbit install to set up services (platform-aware: Homebrew on Mac, Docker on Linux)
        // The install command handles TLD configuration, so we pass it here
        // Note: install command doesn't support --json, so we capture all output
        $result = Process::timeout(600)
            ->env(['PATH' => $this->getSafePath()])
            ->run("{$phpBinary} {$cliPath} install --tld={$tld} --yes 2>&1");

        if (! $result->successful()) {
            $output = $result->output().$result->errorOutput();

            return [
                'success' => false,
                'error' => 'Failed to initialize services: '.($output ?: 'Unknown error'),
            ];
        }

        return ['success' => true];
    }

    /**
     * Configure the TLD in orbit config.
     */
    protected function configureTld(string $tld): array
    {
        $cliPath = $this->cliUpdate->getPharPath();
        $phpBinary = PHP_BINARY;

        // Set TLD via CLI config command
        $result = Process::timeout(30)
            ->env(['PATH' => $this->getSafePath()])
            ->run("{$phpBinary} {$cliPath} config:set tld {$tld} --json 2>&1");

        if (! $result->successful()) {
            // TLD config might not exist yet, that's okay
            return ['success' => true];
        }

        return ['success' => true];
    }

    /**
     * Create the local environment record.
     */
    protected function createLocalEnvironment(string $tld): array
    {
        try {
            // Check if local environment already exists
            $existing = Environment::where('is_local', true)->first();

            if ($existing) {
                $existing->update(['tld' => $tld]);

                return ['success' => true, 'environment' => $existing];
            }

            // Create new local environment
            $environment = Environment::create([
                'name' => 'Local',
                'host' => 'localhost',
                'user' => get_current_user(),
                'port' => 22,
                'is_local' => true,
                'is_default' => true,
                'tld' => $tld,
                'status' => Environment::STATUS_ACTIVE,
            ]);

            return ['success' => true, 'environment' => $environment];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to create environment: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Run setup synchronously and return final result.
     */
    public function runSetupSync(string $tld = 'test'): array
    {
        $results = [];
        $success = true;
        $lastError = null;

        foreach ($this->runSetup($tld) as $stepId => $result) {
            $results[$stepId] = $result;

            if (! $result['success']) {
                $success = false;
                $lastError = $result['error'] ?? 'Unknown error';
                break;
            }
        }

        return [
            'success' => $success,
            'steps' => $results,
            'error' => $lastError,
        ];
    }

    /**
     * Get a safe PATH that includes common locations for tools.
     * This is needed because GUI apps on macOS don't inherit the user's shell PATH.
     */
    protected function getSafePath(): string
    {
        $currentPath = getenv('PATH') ?: '';
        $commonPaths = [
            '/Users/' . get_current_user() . '/.orbstack/bin',
            '/opt/homebrew/bin',
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
            '/usr/sbin',
            '/sbin',
        ];

        return implode(':', array_unique(array_filter(array_merge(
            explode(':', $currentPath),
            $commonPaths
        ))));
    }
}
