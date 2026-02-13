<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Enums\ExitCode;
use App\Services\DockerManager;
use LaravelZero\Framework\Commands\Command;

final class UpgradeCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'upgrade
        {--check : Only check for updates without installing}
        {--json : Output as JSON}';

    protected $description = 'Upgrade Orbit to the latest version';

    private const GITHUB_API_URL = 'https://api.github.com/repos/hardimpactdev/orbit-cli/releases/latest';

    public function __construct(
        private DockerManager $dockerManager
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $currentVersion = config('app.version');
        $binaryPath = $this->getRunningBinaryPath();

        if ($binaryPath === null && ! $this->option('check')) {
            return $this->handleError(
                'Upgrade is only available when running as a compiled binary.',
                ExitCode::GeneralError
            );
        }

        $release = $this->fetchLatestRelease();
        if ($release === null) {
            return $this->handleError(
                'Failed to fetch release information from GitHub.',
                ExitCode::GeneralError
            );
        }

        $latestVersion = $release['tag_name'];
        $isUpToDate = $this->isUpToDate($currentVersion, $latestVersion);

        if ($this->option('check')) {
            return $this->handleCheckResult($currentVersion, $latestVersion, $isUpToDate);
        }

        if ($isUpToDate) {
            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'action' => 'upgrade',
                    'current_version' => $currentVersion,
                    'latest_version' => $latestVersion,
                    'upgraded' => false,
                    'message' => 'Already up to date.',
                ]);
            }

            $this->info("You are already running the latest version ({$latestVersion}).");

            return self::SUCCESS;
        }

        $downloadUrl = $this->findBinaryDownloadUrl($release);
        if ($downloadUrl === null) {
            return $this->handleError(
                'Could not find binary download URL for your platform.',
                ExitCode::GeneralError
            );
        }

        if (! $this->wantsJson()) {
            $this->info("Downloading {$latestVersion}...");
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'orbit_');
        if ($tempFile === false) {
            return $this->handleError(
                'Failed to create temporary file.',
                ExitCode::GeneralError
            );
        }

        try {
            if (! $this->downloadFile($downloadUrl, $tempFile)) {
                return $this->handleError(
                    'Failed to download the new version.',
                    ExitCode::GeneralError
                );
            }

            if (! $this->isValidBinary($tempFile)) {
                return $this->handleError(
                    'Downloaded file is not a valid binary.',
                    ExitCode::GeneralError
                );
            }

            @chmod($tempFile, 0755);
            @copy($binaryPath, $binaryPath.'.bak');

            if (! $this->wantsJson()) {
                $this->info("Upgrading from {$currentVersion} to {$latestVersion}...");
                $this->newLine();

                $this->info('Restarting services...');
                try {
                    $this->dockerManager->stopAll();
                    $this->dockerManager->startAll();
                    $this->info('Services restarted.');
                } catch (\Exception) {
                    $this->warn('Failed to restart some services. Run `orbit restart` to try again.');
                }

                $this->newLine();
                $this->info("Successfully upgraded to {$latestVersion}!");
            }

            $jsonOutput = $this->wantsJson() ? json_encode([
                'success' => true,
                'data' => [
                    'action' => 'upgrade',
                    'previous_version' => $currentVersion,
                    'new_version' => $latestVersion,
                    'upgraded' => true,
                ],
            ]) : null;

            $upgradeScript = sys_get_temp_dir().'/orbit-upgrade-'.getmypid().'.sh';
            $scriptContent = sprintf(
                "#!/bin/sh\nsleep 1\nmv %s %s\nrm -f %s\nrm -f \$0\n",
                escapeshellarg($tempFile),
                escapeshellarg($binaryPath),
                escapeshellarg($binaryPath.'.bak')
            );

            file_put_contents($upgradeScript, $scriptContent);
            chmod($upgradeScript, 0755);

            if (PHP_OS_FAMILY === 'Darwin') {
                exec(sprintf('nohup %s > /dev/null 2>&1 &', escapeshellarg($upgradeScript)));
            } else {
                exec(sprintf('setsid %s > /dev/null 2>&1 < /dev/null &', escapeshellarg($upgradeScript)));
            }

            if ($jsonOutput !== null) {
                fwrite(STDOUT, $jsonOutput."\n");
            }

            exit(0);
        } catch (\Throwable $e) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            throw $e;
        }
    }

    private function getRunningBinaryPath(): ?string
    {
        $pharPath = \Phar::running(false);
        if (! empty($pharPath)) {
            return $pharPath;
        }

        $argv0 = $_SERVER['argv'][0] ?? null;
        if ($argv0 !== null && file_exists($argv0) && is_executable($argv0)) {
            return realpath($argv0) ?: $argv0;
        }

        return null;
    }

    private function getPlatformAssetName(): string
    {
        $os = PHP_OS_FAMILY === 'Darwin' ? 'macos' : 'linux';
        $arch = php_uname('m');

        $archMap = match ($arch) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => $arch,
        };

        return "orbit-{$os}-{$archMap}";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLatestRelease(): ?array
    {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: orbit-cli\r\n",
                'timeout' => 30,
            ],
        ]);

        $response = @file_get_contents(self::GITHUB_API_URL, false, $context);
        if ($response === false) {
            return null;
        }

        /** @var array<string, mixed>|null */
        $data = json_decode($response, true);

        return is_array($data) ? $data : null;
    }

    private function isUpToDate(string $currentVersion, string $latestVersion): bool
    {
        $current = ltrim($currentVersion, 'v');
        $latest = ltrim($latestVersion, 'v');

        if ($current === '@version@') {
            return false;
        }

        return version_compare($current, $latest, '>=');
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function findBinaryDownloadUrl(array $release): ?string
    {
        /** @var array<int, array<string, mixed>> $assets */
        $assets = $release['assets'] ?? [];
        $expectedName = $this->getPlatformAssetName();

        foreach ($assets as $asset) {
            $name = $asset['name'] ?? '';
            if ($name === $expectedName) {
                return $asset['browser_download_url'] ?? null;
            }
        }

        return null;
    }

    private function downloadFile(string $url, string $destination): bool
    {
        $command = sprintf(
            'curl -fSL --max-time 300 -o %s %s 2>/dev/null',
            escapeshellarg($destination),
            escapeshellarg($url)
        );

        $result = null;
        $output = null;
        exec($command, $output, $result);

        return $result === 0 && file_exists($destination) && filesize($destination) > 0;
    }

    private function isValidBinary(string $path): bool
    {
        $size = @filesize($path);
        if ($size === false || $size < 100000) {
            return false;
        }

        if (is_executable($path)) {
            return true;
        }

        $content = @file_get_contents($path, false, null, 0, 1024);
        if ($content === false) {
            return false;
        }

        // ELF binary (Linux)
        if (str_starts_with($content, "\x7fELF")) {
            return true;
        }

        // Mach-O binary (macOS) - both 64-bit and universal
        $magic = unpack('N', substr($content, 0, 4));

        return $magic && in_array($magic[1], [0xFEEDFACF, 0xCAFEBABE, 0xBEBAFECA], true);
    }

    private function handleCheckResult(string $currentVersion, string $latestVersion, bool $isUpToDate): int
    {
        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'action' => 'check',
                'current_version' => $currentVersion,
                'latest_version' => $latestVersion,
                'up_to_date' => $isUpToDate,
                'update_available' => ! $isUpToDate,
            ]);
        }

        $this->info("Current version: {$currentVersion}");
        $this->info("Latest version:  {$latestVersion}");

        if ($isUpToDate) {
            $this->info('You are up to date!');
        } else {
            $this->warn('An update is available. Run `orbit upgrade` to install.');
        }

        return self::SUCCESS;
    }

    private function handleError(string $message, ExitCode $exitCode): int
    {
        if ($this->wantsJson()) {
            return $this->outputJsonError($message, $exitCode->value);
        }

        $this->error($message);

        return $exitCode->value;
    }
}
