<?php

declare(strict_types=1);

namespace App\Commands\Service;

use App\Concerns\ValidatesPort;
use App\Services\ServiceManager;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * MySQL service management command.
 *
 * Usage:
 *   orbit service:mysql setup
 *   orbit service:mysql configure
 *   orbit service:mysql enable
 *   orbit service:mysql disable
 *   orbit service:mysql status
 */
final class ServiceMysqlCommand extends Command
{
    use ValidatesPort;

    protected $signature = 'service:mysql
                            {action=setup : Action to perform (setup, configure, enable, disable, status)}
                            {--port= : Port number}
                            {--password= : Root password}
                            {--database= : Default database name}';

    protected $description = 'Manage MySQL service';

    public function handle(ServiceManager $serviceManager): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'setup', 'configure' => $this->setup($serviceManager),
            'enable' => $this->enable($serviceManager),
            'disable' => $this->disable($serviceManager),
            'status' => $this->status($serviceManager),
            default => $this->unknownAction($action),
        };
    }

    private function setup(ServiceManager $serviceManager): int
    {
        // Check if already configured
        if ($serviceManager->isEnabled('mysql')) {
            $this->warn('MySQL is already configured');
            if (! $this->confirm('Reconfigure? This will reset the database.', false)) {
                return self::SUCCESS;
            }
        }

        $this->newLine();
        $this->info('MySQL Configuration');
        $this->line('<fg=gray>Press Enter to accept defaults</>');
        $this->newLine();

        // Use provided options or prompt
        $version = $this->option('port') ? null : select(
            label: 'Version',
            options: [
                '9.1' => '9.* (latest)',
                '8.4' => '8.* (LTS)',
            ],
            default: '9.1',
        );

        $port = $this->option('port') ?? text(
            label: 'Port',
            default: '3306',
            validate: fn (string $value) => $this->validatePort($value),
        );

        $rootPassword = $this->option('password');
        if ($rootPassword === null) {
            $input = password(
                label: 'Root password',
                hint: 'Default: secret (press Enter to use default)',
            );
            $rootPassword = $input !== '' ? $input : 'secret';
        }

        $defaultDb = $this->option('database') ?? text(
            label: 'Default database name (optional)',
            placeholder: 'Leave empty to skip',
        );

        $config = [
            'version' => $version ?? '9.1',
            'port' => (int) $port,
            'environment' => [
                'MYSQL_ROOT_PASSWORD' => $rootPassword,
            ],
        ];

        if ($defaultDb !== '') {
            $config['environment']['MYSQL_DATABASE'] = $defaultDb;
        }

        // Enable, configure, and start
        $serviceManager->enable('mysql');
        $serviceManager->configure('mysql', $config);
        $serviceManager->regenerateCompose();

        $this->line('');
        $this->line('Starting MySQL...');
        $serviceManager->start('mysql');

        $this->newLine();
        $this->info('✓ MySQL configured and started');
        $this->line("  Port: {$port}");
        $this->line("  Root password: {$rootPassword}");
        if ($defaultDb !== '') {
            $this->line("  Database: {$defaultDb}");
        }
        $this->newLine();
        $this->line('Connect with:');
        $this->line("  mysql -h 127.0.0.1 -P {$port} -u root -p{$rootPassword}");
        $this->newLine();

        return self::SUCCESS;
    }

    private function enable(ServiceManager $serviceManager): int
    {
        if ($serviceManager->isEnabled('mysql')) {
            $this->info('MySQL is already enabled');

            return self::SUCCESS;
        }

        $serviceManager->enable('mysql');
        $serviceManager->regenerateCompose();
        $serviceManager->start('mysql');

        $this->info('✓ MySQL enabled and started');

        return self::SUCCESS;
    }

    private function disable(ServiceManager $serviceManager): int
    {
        if (! $serviceManager->isEnabled('mysql')) {
            $this->info('MySQL is not enabled');

            return self::SUCCESS;
        }

        $serviceManager->disable('mysql');
        $serviceManager->regenerateCompose();

        $this->info('✓ MySQL disabled');

        return self::SUCCESS;
    }

    private function status(ServiceManager $serviceManager): int
    {
        $enabled = $serviceManager->isEnabled('mysql');
        $config = $serviceManager->getService('mysql');

        if (! $enabled) {
            $this->warn('MySQL is not enabled');

            return self::SUCCESS;
        }

        $this->info('MySQL Status');
        $this->line('  Enabled: yes');
        $this->line('  Port: '.($config['port'] ?? '3306'));
        $this->line('  Version: '.($config['version'] ?? '9.1'));

        return self::SUCCESS;
    }

    private function unknownAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line('');
        $this->info('Available actions:');
        $this->line('  setup     - Interactive setup (default)');
        $this->line('  configure - Same as setup');
        $this->line('  enable    - Enable and start MySQL');
        $this->line('  disable   - Stop and disable MySQL');
        $this->line('  status    - Show configuration');

        return self::FAILURE;
    }
}
