<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\CaddyManager;
use App\Services\ConfigManager;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\text;

/**
 * Generate SSL certificate for a local domain and create symlinks for Vite valetTls.
 */
final class SecureCommand extends Command
{
    protected $signature = 'secure
                            {domain? : Domain name (e.g., myapp.test)}
                            {--auto : Use project name automatically}
                            {--trust : Trust the Caddy root CA in macOS keychain}';

    protected $description = 'Generate SSL certificate for a local domain';

    public function handle(CaddyManager $caddyManager, ConfigManager $configManager): int
    {
        $domain = $this->argument('domain');

        if ($domain === null) {
            $domain = $this->detectDomain($configManager);
        }

        // Ensure domain has TLD
        if (! str_contains($domain, '.')) {
            $tld = $configManager->getTld();
            $domain .= '.'.$tld;
        }

        $this->info("Generating certificate for: {$domain}");
        $this->newLine();

        // Reload Caddy to generate certificate
        $this->info('Reloading Caddy...');
        $result = $caddyManager->reload();

        if (! $result) {
            $this->error('Failed to reload Caddy');
            $this->line('  <fg=gray>Check config: caddy validate --config ~/.config/orbit/caddy/Caddyfile</>');

            return self::FAILURE;
        }

        $this->info('✓ Caddy reloaded');
        $this->newLine();

        // Check if certificate was generated
        $caddyCert = $this->findCaddyCertificate($domain);
        if ($caddyCert === null) {
            $this->warn('Certificate not yet generated');
            $this->info("Visit https://{$domain} once to trigger certificate generation");
            $this->info("Then run: orbit secure {$domain}");

            return self::FAILURE;
        }

        $this->info('✓ Certificate ready');
        $this->line('  Location: '.$caddyCert['cert']);
        $this->newLine();

        // Create symlinks for Vite valetTls
        $this->createCertificateSymlinks($domain, $caddyCert);

        $this->newLine();

        // Trust Caddy root CA if requested
        if ($this->option('trust')) {
            $this->trustCaddyRootCa();
        } else {
            $this->warn('Caddy CA may not be trusted - run with --trust if you see certificate warnings');
        }

        return self::SUCCESS;
    }

    /**
     * Detect domain from current directory.
     */
    private function detectDomain(ConfigManager $configManager): string
    {
        $cwd = getcwd();
        $project = basename($cwd);
        $tld = $configManager->getTld();

        if ($this->option('auto')) {
            return $project.'.'.$tld;
        }

        $suggested = $project;

        $domain = text(
            label: 'Domain name',
            default: "{$suggested}.{$tld}",
            validate: fn ($v) => $v === '' ? 'Domain is required' : null
        );

        return $domain;
    }

    /**
     * Find Caddy certificate for domain.
     *
     * @return array{cert: string, key: string}|null
     */
    private function findCaddyCertificate(string $domain): ?array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        $basePaths = [
            '/opt/homebrew/var/lib/caddy/certificates/local',
            $home.'/Library/Application Support/Caddy/certificates/local',
        ];

        $paths = [];
        foreach ($basePaths as $basePath) {
            $paths[] = "{$basePath}/{$domain}/{$domain}.crt";
            $paths[] = "{$basePath}/{$domain}.crt";
        }

        foreach ($paths as $certPath) {
            $keyPath = str_replace('.crt', '.key', $certPath);
            if (file_exists($certPath) && file_exists($keyPath)) {
                return ['cert' => $certPath, 'key' => $keyPath];
            }
        }

        return null;
    }

    private function createCertificateSymlinks(string $domain, array $caddyCert): void
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        $configDirs = [
            'Valet' => $home.'/.config/valet',
        ];

        foreach ($configDirs as $name => $configDir) {
            $certDir = $configDir.'/Certificates';

            if (! is_dir($certDir)) {
                continue;
            }

            $this->line("Updating: {$certDir}");

            $certLink = "{$certDir}/{$domain}.crt";
            $keyLink = "{$certDir}/{$domain}.key";

            if (file_exists($certLink) || is_link($certLink)) {
                unlink($certLink);
            }
            if (file_exists($keyLink) || is_link($keyLink)) {
                unlink($keyLink);
            }

            symlink($caddyCert['cert'], $certLink);
            symlink($caddyCert['key'], $keyLink);
            $this->line("  ✓ {$domain}.crt");
            $this->line("  ✓ {$domain}.key");

            $this->ensureValetConfig($configDir);
        }
    }

    private function ensureValetConfig(string $configDir): void
    {
        $configFile = $configDir.'/config.json';
        $tld = app(ConfigManager::class)->getTld();

        file_put_contents($configFile, json_encode(['tld' => $tld], JSON_PRETTY_PRINT));
    }

    /**
     * Trust Caddy root CA in macOS keychain.
     */
    private function trustCaddyRootCa(): void
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        $rootCaPath = collect([
            '/opt/homebrew/var/lib/caddy/pki/authorities/local/root.crt',
            $home.'/Library/Application Support/Caddy/pki/authorities/local/root.crt',
        ])->first(fn (string $path) => file_exists($path));

        if ($rootCaPath === null) {
            $this->warn('Caddy root CA not found - visit the site first to generate it');

            return;
        }

        $this->info('Trusting Caddy root CA...');

        // Use caddy trust command
        $result = Process::timeout(60)->run('caddy trust');

        if ($result->successful()) {
            $this->info('✓ Caddy root CA trusted');
            $this->info('You may need to restart your browser');
        } elseif (str_contains($result->errorOutput(), 'already')) {
            $this->info('✓ Caddy root CA already trusted');
        } else {
            $this->error('Failed to trust Caddy root CA');
            $this->line($result->errorOutput());
            $this->line('  <fg=gray>Try manually: sudo security add-trusted-cert -d -r trustRoot /path/to/root.crt</>');
        }
    }
}
