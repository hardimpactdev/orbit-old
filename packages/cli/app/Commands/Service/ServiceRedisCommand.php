<?php

declare(strict_types=1);

namespace App\Commands\Service;

use App\Concerns\ValidatesPort;
use App\Services\ServiceManager;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Redis service management command.
 *
 * Usage:
 *   orbit service:redis setup
 *   orbit service:redis configure --maxmemory=512mb
 *   orbit service:redis enable
 *   orbit service:redis disable
 *   orbit service:redis status
 */
final class ServiceRedisCommand extends Command
{
    use ValidatesPort;

    protected $signature = 'service:redis
                            {action=setup : Action to perform (setup, configure, enable, disable, status)}
                            {--port= : Port number}
                            {--maxmemory= : Max memory (e.g., 256mb, 512mb)}';

    protected $description = 'Manage Redis service';

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
        if ($serviceManager->isEnabled('redis')) {
            $this->warn('Redis is already configured');
            if (! $this->confirm('Reconfigure?', false)) {
                return self::SUCCESS;
            }
        }

        $this->newLine();
        $this->info('Redis Configuration');
        $this->line('<fg=gray>Press Enter to accept defaults</>');
        $this->newLine();

        // Use provided options or prompt
        $port = $this->option('port') ?? text(
            label: 'Port',
            default: '6379',
            validate: fn (string $value) => $this->validatePort($value),
        );

        $maxMemory = $this->option('maxmemory') ?? text(
            label: 'Max memory',
            default: '256mb',
            hint: 'e.g., 128mb, 256mb, 512mb, 1gb',
        );

        $persistence = select(
            label: 'Enable persistence?',
            options: [
                'yes' => 'Yes (save data to disk)',
                'no' => 'No (in-memory only)',
            ],
            default: 'yes',
        );

        $config = [
            'port' => (int) $port,
            'maxmemory' => $maxMemory,
            'persistence' => $persistence === 'yes',
        ];

        // Enable, configure, and start
        $serviceManager->enable('redis');
        $serviceManager->configure('redis', $config);
        $serviceManager->regenerateCompose();

        $this->line('');
        $this->line('Starting Redis...');
        $serviceManager->start('redis');

        $this->newLine();
        $this->info('✓ Redis configured and started');
        $this->line("  Port: {$port}");
        $this->line("  Max memory: {$maxMemory}");
        $this->line('  Persistence: '.($persistence === 'yes' ? 'enabled' : 'disabled'));
        $this->newLine();
        $this->line('Connect with:');
        $this->line("  redis-cli -p {$port}");
        $this->newLine();

        return self::SUCCESS;
    }

    private function enable(ServiceManager $serviceManager): int
    {
        if ($serviceManager->isEnabled('redis')) {
            $this->info('Redis is already enabled');

            return self::SUCCESS;
        }

        $serviceManager->enable('redis');
        $serviceManager->regenerateCompose();
        $serviceManager->start('redis');

        $this->info('✓ Redis enabled and started');

        return self::SUCCESS;
    }

    private function disable(ServiceManager $serviceManager): int
    {
        if (! $serviceManager->isEnabled('redis')) {
            $this->info('Redis is not enabled');

            return self::SUCCESS;
        }

        $serviceManager->disable('redis');
        $serviceManager->regenerateCompose();

        $this->info('✓ Redis disabled');

        return self::SUCCESS;
    }

    private function status(ServiceManager $serviceManager): int
    {
        $enabled = $serviceManager->isEnabled('redis');
        $config = $serviceManager->getService('redis');

        if (! $enabled) {
            $this->warn('Redis is not enabled');

            return self::SUCCESS;
        }

        $this->info('Redis Status');
        $this->line('  Enabled: yes');
        $this->line('  Port: '.($config['port'] ?? '6379'));
        $this->line('  Max memory: '.($config['maxmemory'] ?? '256mb'));
        $persistence = $config['persistence'] ?? true;
        $this->line('  Persistence: '.($persistence === true ? 'enabled' : 'disabled'));

        return self::SUCCESS;
    }

    private function unknownAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line('');
        $this->info('Available actions:');
        $this->line('  setup     - Interactive setup (default)');
        $this->line('  configure - Same as setup');
        $this->line('  enable    - Enable and start Redis');
        $this->line('  disable   - Stop and disable Redis');
        $this->line('  status    - Show configuration');

        return self::FAILURE;
    }
}
