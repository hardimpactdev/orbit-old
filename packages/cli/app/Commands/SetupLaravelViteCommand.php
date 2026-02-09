<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ConfigManager;
use LaravelZero\Framework\Commands\Command;

/**
 * One-time setup to create certificate directories for Vite valetTls support.
 *
 * Creates the Herd/Valet certificate directories that `orbit secure` will use.
 */
final class SetupLaravelViteCommand extends Command
{
    protected $signature = 'setup:laravel-vite';

    protected $description = 'Setup certificate directories for Vite valetTls support';

    public function handle(ConfigManager $configManager): int
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        $configDirs = [
            'Herd' => $home.'/Library/Application Support/Herd/config/valet',
            'Valet' => $home.'/.config/valet',
        ];

        $this->info('Setting up certificate directories for Vite valetTls support');
        $this->newLine();

        $tld = $configManager->getTld();

        foreach ($configDirs as $name => $configDir) {
            $certDir = $configDir.'/Certificates';
            $this->line("{$name}: {$certDir}");

            if (is_dir($certDir)) {
                $this->line('  ✓ Directory exists');
            } else {
                mkdir($certDir, 0755, true);
                $this->line('  ✓ Directory created');
            }

            $configFile = $configDir.'/config.json';
            file_put_contents($configFile, json_encode(['tld' => $tld], JSON_PRETTY_PRINT));
            $this->line("  ✓ config.json (tld: {$tld})");
        }

        $this->newLine();
        $this->info('Done! Now use "orbit secure" in your project directory');

        return self::SUCCESS;
    }
}
