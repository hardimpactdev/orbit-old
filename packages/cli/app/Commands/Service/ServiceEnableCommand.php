<?php

declare(strict_types=1);

namespace App\Commands\Service;

use App\Concerns\ValidatesPort;
use App\Concerns\WithJsonOutput;
use App\Services\ServiceManager;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class ServiceEnableCommand extends Command
{
    use ValidatesPort;
    use WithJsonOutput;

    protected $signature = 'service:enable
                            {service : Service name to enable}
                            {--json : Output as JSON}';

    protected $description = 'Enable a service';

    public function handle(ServiceManager $serviceManager): int
    {
        $serviceName = $this->argument('service');

        try {
            // Prompt for service-specific configuration
            $config = $this->promptForConfiguration($serviceName);

            // Enable the service
            $success = $serviceManager->enable($serviceName);

            if (! $success) {
                return $this->wantsJson()
                    ? $this->outputJsonError("Failed to enable service: {$serviceName}")
                    : $this->handleError("Failed to enable service: {$serviceName}");
            }

            // Apply any custom configuration
            if ($config !== []) {
                $serviceManager->configure($serviceName, $config);
            }

            // Regenerate docker-compose.yaml to reflect changes
            $serviceManager->regenerateCompose();

            // Check for service-specific dependencies
            $this->checkServiceDependencies($serviceName);

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'service' => $serviceName,
                    'enabled' => true,
                    'config' => $config,
                    'message' => "Service {$serviceName} has been enabled",
                ]);
            }

            $this->newLine();
            $this->info("  Service '{$serviceName}' has been enabled");

            // Auto-start the service
            if (! $this->wantsJson()) {
                $this->line("  Starting {$serviceName}...");
                try {
                    $success = $serviceManager->start($serviceName);
                    if ($success) {
                        $this->info("  ✓ {$serviceName} started");
                    } else {
                        $this->warn("  {$serviceName} failed to start");
                        if ($serviceName === 'mysql') {
                            $this->line('  <fg=gray>This may be due to version mismatch with existing data.</>');
                            $this->line('  <fg=gray>To fix: rm -rf ~/.config/orbit/data/mysql and re-enable</>');
                        }
                    }
                } catch (\Exception $e) {
                    $this->warn("  Could not start {$serviceName}: {$e->getMessage()}");
                }
            }
            $this->newLine();

            return self::SUCCESS;

        } catch (RuntimeException $e) {
            return $this->wantsJson()
                ? $this->outputJsonError($e->getMessage())
                : $this->handleError($e->getMessage());
        }
    }

    /**
     * Prompt for service-specific configuration.
     *
     * @return array<string, mixed>
     */
    protected function promptForConfiguration(string $serviceName): array
    {
        if ($this->wantsJson()) {
            return [];
        }

        return match ($serviceName) {
            'mysql' => $this->promptForMysqlConfig(),
            'postgres' => $this->promptForPostgresConfig(),
            default => [],
        };
    }

    /**
     * Prompt for MySQL configuration.
     *
     * @return array<string, mixed>
     */
    protected function promptForMysqlConfig(): array
    {
        $this->newLine();
        $this->info('  MySQL Configuration');
        $this->line('  <fg=gray>Press Enter to accept defaults</>');
        $this->newLine();

        // Version selection - simplified to major versions
        $version = select(
            label: 'Version',
            options: [
                '9.1' => '9.* (latest)',
                '8.4' => '8.* (LTS)',
            ],
            default: '9.1',
        );

        $port = text(
            label: 'Port',
            default: '3306',
            validate: fn (string $value) => $this->validatePort($value),
        );

        $rootPassword = $this->promptPassword('Root password', 'secret');
        $defaultDb = text(
            label: 'Default database name',
            default: 'orbit',
            hint: 'Leave empty to skip creating a default database',
        );

        $environment = [
            'MYSQL_ROOT_PASSWORD' => $rootPassword,
        ];

        if ($defaultDb !== '') {
            $environment['MYSQL_DATABASE'] = $defaultDb;
        }

        $config = [
            'version' => $version,
            'port' => (int) $port,
            'environment' => $environment,
        ];

        $this->newLine();

        return $config;
    }

    /**
     * Prompt for PostgreSQL configuration.
     *
     * @return array<string, mixed>
     */
    protected function promptForPostgresConfig(): array
    {
        $this->newLine();
        $this->info('  PostgreSQL Configuration');
        $this->line('  <fg=gray>Press Enter to accept defaults</>');
        $this->newLine();

        $port = text(
            label: 'Port',
            default: '5432',
            validate: fn (string $value) => $this->validatePort($value),
        );

        $config = [
            'port' => (int) $port,
            'environment' => [
                'POSTGRES_USER' => text(
                    label: 'Username',
                    default: 'orbit',
                ),
                'POSTGRES_PASSWORD' => $this->promptPassword('Password', 'secret'),
                'POSTGRES_DB' => text(
                    label: 'Default database',
                    default: 'orbit',
                ),
            ],
        ];

        $this->newLine();

        return $config;
    }

    /**
     * Prompt for password with a default value.
     */
    protected function promptPassword(string $label, string $default): string
    {
        $value = password(
            label: $label,
            hint: "Default: {$default} (press Enter to use default)",
        );

        // If empty, use default
        return $value !== '' ? $value : $default;
    }

    /**
     * Check and install service-specific host dependencies.
     */
    protected function checkServiceDependencies(string $serviceName): void
    {
        if ($serviceName === 'mysql') {
            $this->setupMysqlConfigFiles();
            if (PHP_OS_FAMILY === 'Darwin') {
                $this->checkMysqlClient();
            }
        }
    }

    /**
     * Copy MySQL init files to config directory.
     */
    protected function setupMysqlConfigFiles(): void
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? '');
        $configPath = $home.'/.config/orbit';
        $mysqlDir = $configPath.'/mysql';

        // Create mysql config directory
        if (! is_dir($mysqlDir)) {
            mkdir($mysqlDir, 0755, true);
        }

        // Copy init.sql from stubs
        $initSqlPath = $mysqlDir.'/init.sql';
        if (! file_exists($initSqlPath)) {
            $stubPath = base_path('stubs/mysql/init.sql');
            if (file_exists($stubPath)) {
                copy($stubPath, $initSqlPath);
            } else {
                // Create default init.sql if stub doesn't exist (e.g., phar)
                $defaultInitSql = <<<'SQL'
-- Grant all privileges to the orbit user
GRANT ALL PRIVILEGES ON *.* TO 'orbit'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL;
                file_put_contents($initSqlPath, $defaultInitSql);
            }
        }
    }

    /**
     * Check if mysql-client is installed and offer to install it.
     */
    protected function checkMysqlClient(): void
    {
        // Check if mysql command exists
        exec('which mysql 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            if ($this->wantsJson()) {
                return;
            }

            $this->newLine();
            $this->warn('  MySQL client not found on your system.');
            $this->line('  <fg=gray>Laravel needs the mysql CLI to load schema dumps.</>');

            if ($this->confirm('  Install mysql-client via Homebrew?', true)) {
                $this->line('  Installing mysql-client...');

                exec('brew install mysql-client 2>&1', $brewOutput, $brewExit);

                if ($brewExit === 0) {
                    $this->info('  ✓ mysql-client installed');
                    $this->newLine();
                    $this->warn('  Add to your shell PATH:');
                    $this->line('  echo \'export PATH="/opt/homebrew/opt/mysql-client/bin:$PATH"\' >> ~/.zshrc');
                    $this->line('  source ~/.zshrc');
                } else {
                    $this->error('  Failed to install mysql-client');
                }
            }
        }
    }

    protected function handleError(string $message): int
    {
        $this->newLine();
        $this->error("  {$message}");
        $this->newLine();

        return self::FAILURE;
    }
}
