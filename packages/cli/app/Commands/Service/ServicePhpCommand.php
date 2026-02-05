<?php

declare(strict_types=1);

namespace App\Commands\Service;

use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * PHP service management command.
 *
 * Usage:
 *   orbit service:php set-memory-limit 256M
 *   orbit service:php configure
 *   orbit service:php status
 */
final class ServicePhpCommand extends Command
{
    protected $signature = 'service:php
                            {action=status : Action to perform (set-memory-limit, configure, status)}
                            {value? : Memory limit value (e.g., 128M, 256M, -1)}';

    protected $description = 'Manage PHP configuration';

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'set-memory-limit' => $this->setMemoryLimit(),
            'configure' => $this->configurePhp(),
            'status' => $this->status(),
            default => $this->unknownAction($action),
        };
    }

    private function setMemoryLimit(): int
    {
        $limit = $this->argument('value');

        if ($limit === null) {
            $limit = text(
                label: 'Memory limit',
                default: '128M',
                hint: 'e.g., 128M, 256M, 512M, or -1 for unlimited',
            );
        }

        // Validate the limit format
        if (! $this->isValidMemoryLimit($limit)) {
            $this->error("Invalid memory limit format: {$limit}");
            $this->line('Use format like: 128M, 256M, 512M, 1G, or -1 for unlimited');

            return self::FAILURE;
        }

        // Update PHP-FPM pool configurations
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/tmp');
        $phpDir = $home.'/.config/orbit/php';

        $fpmUpdated = 0;
        foreach (glob($phpDir.'/*.conf') as $file) {
            $content = file_get_contents($file);
            $newContent = preg_replace(
                '/php_admin_value\[memory_limit\] = .+/',
                "php_admin_value[memory_limit] = {$limit}",
                $content
            );
            if ($newContent !== $content) {
                file_put_contents($file, $newContent);
                $fpmUpdated++;
            }
        }

        // Update CLI php.ini (Homebrew PHP)
        $cliUpdated = false;
        $phpIniPath = '/opt/homebrew/etc/php/8.5/php.ini';
        if (! file_exists($phpIniPath)) {
            $phpIniPath = '/opt/homebrew/etc/php/8.4/php.ini';
        }

        if (file_exists($phpIniPath)) {
            $content = file_get_contents($phpIniPath);
            $newContent = preg_replace(
                '/^memory_limit = .+$/m',
                "memory_limit = {$limit}",
                $content
            );
            if ($newContent !== $content) {
                file_put_contents($phpIniPath, $newContent);
                $cliUpdated = true;
            }
        }

        $this->info("PHP memory limit set to: {$limit}");
        $this->line("Updated PHP-FPM pool(s): {$fpmUpdated}");
        $this->line('Updated CLI php.ini: '.($cliUpdated ? 'yes' : 'no'));

        // Auto-restart PHP-FPM if pools were updated
        if ($fpmUpdated > 0) {
            $this->newLine();
            $this->line('Restarting PHP-FPM...');
            $this->call('restart');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function configurePhp(): int
    {
        $this->newLine();
        $this->info('PHP Configuration');
        $this->newLine();

        $scope = select(
            label: 'Configure for',
            options: [
                'fpm' => 'PHP-FPM (web requests)',
                'cli' => 'CLI (command line)',
            ],
            default: 'fpm',
        );

        if ($scope === 'fpm') {
            return $this->setMemoryLimit();
        }

        // CLI configuration
        $limit = text(
            label: 'CLI Memory limit',
            default: '-1',
            hint: '-1 for unlimited, or specify like 512M',
        );

        $this->info("CLI memory limit would be set to: {$limit}");
        $this->line('Note: CLI uses system php.ini, edit manually or use:');
        $this->line("  php -d memory_limit={$limit} <command>");

        return self::SUCCESS;
    }

    private function status(): int
    {
        $this->info('PHP Memory Limits');
        $this->newLine();

        // CLI status
        $cliLimit = ini_get('memory_limit');
        $this->line("CLI (current): {$cliLimit}");

        // PHP-FPM status
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/tmp');
        $phpDir = $home.'/.config/orbit/php';

        $pools = glob($phpDir.'/*.conf');
        if (empty($pools)) {
            $this->warn('No PHP-FPM pools found');
        } else {
            foreach ($pools as $file) {
                $content = file_get_contents($file);
                $basename = basename($file);

                if (preg_match('/php_admin_value\[memory_limit\] = (.+)/', $content, $matches)) {
                    $this->line("PHP-FPM ({$basename}): {$matches[1]}");
                } else {
                    $this->line("PHP-FPM ({$basename}): default");
                }
            }
        }

        $this->newLine();
        $this->line('Change with:');
        $this->line('  orbit service:php set-memory-limit 256M');

        return self::SUCCESS;
    }

    private function unknownAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line('');
        $this->info('Available actions:');
        $this->line('  set-memory-limit [value]  - Set PHP-FPM memory limit');
        $this->line('  configure                 - Interactive configuration');
        $this->line('  status                    - Show current configuration');

        return self::FAILURE;
    }

    private function isValidMemoryLimit(string $limit): bool
    {
        if ($limit === '-1') {
            return true;
        }

        return preg_match('/^\d+[KMG]?$/i', $limit) === 1;
    }
}
