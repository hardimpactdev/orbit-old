<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class PackageLinkCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'package:link
        {package : The package project name to link}
        {app : The app project name to link it to}
        {--json : Output as JSON}';

    protected $description = 'Link a local package to an app for development (uses composer-link)';

    public function handle(): int
    {
        $package = $this->argument('package');
        $app = $this->argument('app');

        $projectsDir = getenv('HOME').'/projects';
        $packagePath = "$projectsDir/$package";
        $appPath = "$projectsDir/$app";

        // Validate paths exist
        if (! File::isDirectory($packagePath)) {
            if ($this->wantsJson()) {
                return $this->outputJsonError("Package '$package' not found at $packagePath. Check project exists in ~/projects/");
            }
            $this->error("Package '$package' not found at $packagePath");
            $this->line('  <fg=gray>Check project exists in ~/projects/</>');

            return self::FAILURE;
        }

        if (! File::isDirectory($appPath)) {
            if ($this->wantsJson()) {
                return $this->outputJsonError("App '$app' not found at $appPath. Check project exists in ~/projects/");
            }
            $this->error("App '$app' not found at $appPath");
            $this->line('  <fg=gray>Check project exists in ~/projects/</>');

            return self::FAILURE;
        }

        // Check package has composer.json
        if (! File::exists("$packagePath/composer.json")) {
            if ($this->wantsJson()) {
                return $this->outputJsonError("Package '$package' has no composer.json. Ensure the package directory is correct");
            }
            $this->error("Package '$package' has no composer.json");
            $this->line('  <fg=gray>Ensure the package directory is correct</>');

            return self::FAILURE;
        }

        // Run composer link
        $result = Process::path($appPath)->run("composer link $packagePath");

        if (! $result->successful()) {
            if ($this->wantsJson()) {
                return $this->outputJsonError('Failed to link package: '.$result->errorOutput());
            }
            $this->error('Failed to link package:');
            $this->line($result->errorOutput());
            $this->line('  <fg=gray>Ensure composer-link plugin is installed: composer global require sllh/composer-link</>');

            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'message' => "Package '$package' linked to '$app'",
                'package' => $package,
                'app' => $app,
            ]);
        }

        $this->info("Package '$package' linked to '$app'");
        $this->line($result->output());

        return self::SUCCESS;
    }
}
