<?php

declare(strict_types=1);

namespace App\Commands\Host;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\CaddyManager;
use App\Services\PhpManager;
use LaravelZero\Framework\Commands\Command;

final class HostStopCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'host:stop {service} {--json}';

    protected $description = 'Stop a host service';

    public function handle(
        CaddyManager $caddy,
        PhpManager $php,
    ): int {
        $service = $this->argument('service');

        try {
            if ($service === 'caddy') {
                $success = $caddy->stop();
            } elseif (str_starts_with($service, 'php')) {
                $version = str_replace('php-', '', $service);
                $success = $php->stop($version);
            } else {
                if ($this->wantsJson()) {
                    return $this->outputJsonError("Unknown host service: {$service}. Valid services: caddy, php-8.3, php-8.4, php-8.5", ExitCode::InvalidArguments->value);
                }
                $this->error("Unknown host service: {$service}");
                $this->line('  <fg=gray>Valid services: caddy, php-8.3, php-8.4, php-8.5</>');

                return ExitCode::InvalidArguments->value;
            }

            if ($this->wantsJson()) {
                $statusHint = PHP_OS_FAMILY === 'Darwin'
                    ? "brew services info {$service}"
                    : "sudo systemctl status {$service}";

                return $success
                    ? $this->outputJsonSuccess(['message' => "Stopped {$service}"])
                    : $this->outputJsonError("Failed to stop {$service}. Check status with: {$statusHint}", ExitCode::ServiceFailed->value);
            }

            if ($success) {
                $this->info("Stopped {$service}");

                return self::SUCCESS;
            }

            $this->error("Failed to stop {$service}");
            $statusCmd = PHP_OS_FAMILY === 'Darwin'
                ? "brew services info {$service}"
                : "sudo systemctl status {$service}";
            $this->line("  <fg=gray>Check status with: {$statusCmd}</>");

            return ExitCode::ServiceFailed->value;

        } catch (\Exception $e) {
            if ($this->wantsJson()) {
                return $this->outputJsonError($e->getMessage());
            }
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
