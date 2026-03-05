<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ConfigManager;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

/**
 * Generate SSL certificate for a local domain and create symlinks for Vite valetTls.
 */
final class SecureCommand extends Command
{
    protected $signature = 'secure
                            {domain? : Domain name (e.g., myapp.test)}
                            {--trust : Trust the Caddy root CA in macOS keychain}';

    protected $description = 'Generate SSL certificate for a local domain';

    public function handle(ConfigManager $configManager): int
    {
        $domain = $this->argument('domain') ?? $this->detectDomain($configManager);

        // Ensure domain has TLD
        if (! str_contains($domain, '.')) {
            $tld = $configManager->getTld();
            $domain .= '.'.$tld;
        }

        $this->info("Generating certificate for: {$domain}");
        $this->newLine();

        // Try to find an existing Caddy-generated certificate first
        $cert = $this->findCaddyCertificate($domain);

        if ($cert === null) {
            $cert = $this->generateCertificate($domain);

            if ($cert === null) {
                $this->error('Failed to generate certificate');

                return self::FAILURE;
            }
        }

        $this->info('✓ Certificate ready');
        $this->line('  Location: '.$cert['cert']);
        $this->newLine();

        $this->createCertificateSymlinks($domain, $cert);

        $this->newLine();

        if ($this->option('trust')) {
            $this->trustCaddyRootCa();
        } else {
            $this->warn('Caddy CA may not be trusted - run with --trust if you see certificate warnings');
        }

        return self::SUCCESS;
    }

    private function detectDomain(ConfigManager $configManager): string
    {
        $cwd = getcwd();
        $tld = $configManager->getTld();

        // Try APP_URL from .env
        $envFile = $cwd.'/.env';
        if (file_exists($envFile)) {
            $contents = file_get_contents($envFile);
            if (preg_match('/^APP_URL\s*=\s*(.+)$/m', $contents, $matches)) {
                $url = trim($matches[1], " \t\n\r\0\x0B\"'");
                $host = parse_url($url, PHP_URL_HOST);
                if ($host) {
                    return $host;
                }
            }
        }

        // Fallback to directory name
        return basename($cwd).'.'.$tld;
    }

    /**
     * @return array{cert: string, key: string}|null
     */
    private function findCaddyCertificate(string $domain): ?array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        $basePaths = [
            '/opt/homebrew/var/lib/caddy/certificates/local',
            $home.'/Library/Application Support/Caddy/certificates/local',
        ];

        foreach ($basePaths as $basePath) {
            foreach (["{$basePath}/{$domain}/{$domain}.crt", "{$basePath}/{$domain}.crt"] as $certPath) {
                $keyPath = str_replace('.crt', '.key', $certPath);
                if (file_exists($certPath) && file_exists($keyPath)) {
                    return ['cert' => $certPath, 'key' => $keyPath];
                }
            }
        }

        return null;
    }

    /**
     * @return array{cert: string, key: string}|null
     */
    private function generateCertificate(string $domain): ?array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        $caDir = collect([
            '/opt/homebrew/var/lib/caddy/pki/authorities/local',
            $home.'/Library/Application Support/Caddy/pki/authorities/local',
        ])->first(fn (string $path) => file_exists($path.'/intermediate.crt'));

        if ($caDir === null) {
            $this->error('Caddy local CA not found');
            $this->line('  <fg=gray>Ensure Caddy is installed and has been started at least once</>');

            return null;
        }

        $caCert = $caDir.'/intermediate.crt';
        $caKey = $caDir.'/intermediate.key';

        $certDir = collect([
            '/opt/homebrew/var/lib/caddy/certificates/local',
            $home.'/Library/Application Support/Caddy/certificates/local',
        ])->first(fn (string $path) => is_dir($path));

        if ($certDir === null) {
            $this->error('Caddy certificate directory not found');

            return null;
        }

        $outputDir = $certDir.'/'.$domain;

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0700, true);
        }

        $certPath = $outputDir.'/'.$domain.'.crt';
        $keyPath = $outputDir.'/'.$domain.'.key';

        $this->info('Generating certificate using Caddy local CA...');

        // Generate private key
        $result = Process::run('openssl genrsa -out '.escapeshellarg($keyPath).' 2048 2>&1');
        if (! $result->successful()) {
            $this->error('Failed to generate private key: '.$result->output());

            return null;
        }

        // Create openssl config with SAN
        $opensslConf = tempnam(sys_get_temp_dir(), 'orbit-ssl-');
        file_put_contents($opensslConf, implode("\n", [
            '[req]',
            'distinguished_name = req_distinguished_name',
            'req_extensions = v3_req',
            'prompt = no',
            '',
            '[req_distinguished_name]',
            'CN = '.$domain,
            '',
            '[v3_req]',
            'basicConstraints = CA:FALSE',
            'keyUsage = digitalSignature, keyEncipherment',
            'subjectAltName = DNS:'.$domain,
        ]));

        // Generate CSR
        $csrPath = tempnam(sys_get_temp_dir(), 'orbit-csr-');
        $result = Process::run(implode(' ', [
            'openssl', 'req', '-new',
            '-key', escapeshellarg($keyPath),
            '-out', escapeshellarg($csrPath),
            '-config', escapeshellarg($opensslConf),
        ]).' 2>&1');

        if (! $result->successful()) {
            $this->error('Failed to generate CSR: '.$result->output());
            @unlink($opensslConf);
            @unlink($csrPath);

            return null;
        }

        // Sign with Caddy's intermediate CA
        $result = Process::run(implode(' ', [
            'openssl', 'x509', '-req',
            '-in', escapeshellarg($csrPath),
            '-CA', escapeshellarg($caCert),
            '-CAkey', escapeshellarg($caKey),
            '-CAcreateserial',
            '-out', escapeshellarg($certPath),
            '-days', '825',
            '-extensions', 'v3_req',
            '-extfile', escapeshellarg($opensslConf),
        ]).' 2>&1');

        @unlink($opensslConf);
        @unlink($csrPath);

        if (! $result->successful()) {
            $this->error('Failed to sign certificate: '.$result->output());

            return null;
        }

        return ['cert' => $certPath, 'key' => $keyPath];
    }

    private function createCertificateSymlinks(string $domain, array $cert): void
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';
        $certDir = $home.'/.config/valet/Certificates';

        if (! is_dir($certDir)) {
            mkdir($certDir, 0755, true);
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

        symlink($cert['cert'], $certLink);
        symlink($cert['key'], $keyLink);
        $this->line("  ✓ {$domain}.crt");
        $this->line("  ✓ {$domain}.key");

        $this->ensureValetConfig(dirname($certDir));
    }

    private function ensureValetConfig(string $configDir): void
    {
        $configFile = $configDir.'/config.json';
        $tld = app(ConfigManager::class)->getTld();

        file_put_contents($configFile, json_encode(['tld' => $tld], JSON_PRETTY_PRINT));
    }

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
