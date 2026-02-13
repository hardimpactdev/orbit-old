<?php

declare(strict_types=1);

namespace App\Actions\Install\Mac;

use App\Data\Install\InstallContext;
use App\Services\Install\InstallLogger;
use HardImpact\Orbit\Core\Data\StepResult;
use Illuminate\Support\Facades\Process;

final readonly class TrustRootCa
{
    public function handle(InstallContext $context, InstallLogger $logger): StepResult
    {
        if ($context->skipTrust) {
            $logger->skip('Certificate trust skipped');

            return StepResult::success();
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        $pkiPaths = [
            '/opt/homebrew/var/lib/caddy/pki/authorities/local',
            $home.'/Library/Application Support/Caddy/pki/authorities/local',
        ];

        $pkiDir = collect($pkiPaths)->first(fn (string $p) => file_exists($p.'/root.crt'));

        if ($pkiDir === null) {
            $logger->warn('Caddy PKI directory not found');

            return StepResult::success();
        }

        $certPath = $pkiDir.'/root.crt';
        $intermediatePath = $pkiDir.'/intermediate.crt';

        if (file_exists($certPath)) {
            $rootTrusted = Process::run('security find-certificate -c "Caddy Local Authority" /Library/Keychains/System.keychain 2>/dev/null')->successful();
            $intermediateTrusted = Process::run('security find-certificate -c "Caddy Local Authority - ECC Intermediate" /Library/Keychains/System.keychain 2>/dev/null')->successful();

            if ($rootTrusted && $intermediateTrusted) {
                $logger->success('Caddy certificates already trusted');

                return StepResult::success();
            }
        }

        $logger->step('Trusting Caddy root certificate (authorization required)...');
        $logger->warn('A system dialog may appear - please authorize to trust the certificate');

        // Trust root CA
        $rootResult = Process::timeout(60)->run("sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain '{$certPath}'");

        if (! $rootResult->successful()) {
            $logger->warn('Could not automatically trust root certificate');
        }

        // Also trust intermediate CA (needed for site certificates)
        if (file_exists($intermediatePath)) {
            Process::timeout(60)->run("sudo security add-trusted-cert -d -r trustAsRoot -k /Library/Keychains/System.keychain '{$intermediatePath}'");
        }

        $logger->success('Certificate trusted');
        $logger->info('You may need to restart your browser');

        return StepResult::success();
    }
}
