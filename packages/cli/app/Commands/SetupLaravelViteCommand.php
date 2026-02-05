<?php

declare(strict_types=1);

namespace App\Commands;

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

    public function handle(): int
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        // Target directories (Herd and Valet)
        $targets = [
            'Herd' => $home.'/Library/Application Support/Herd/config/valet/Certificates',
            'Valet' => $home.'/.config/valet/Certificates',
        ];

        $this->info('Setting up certificate directories for Vite valetTls support');
        $this->newLine();

        foreach ($targets as $name => $targetDir) {
            $this->line("{$name}: {$targetDir}");

            if (is_dir($targetDir)) {
                $this->line('  ✓ Already exists');
            } else {
                mkdir($targetDir, 0755, true);
                $this->line('  ✓ Created');
            }
        }

        $this->newLine();
        $this->info('Done! Now use "orbit secure" in your project directory');

        return self::SUCCESS;
    }
}
