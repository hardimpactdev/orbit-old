<?php

declare(strict_types=1);

namespace App\Concerns;

use HardImpact\Orbit\Core\Models\Gateway;
use Illuminate\Support\Facades\Process;

trait RunsOnGateway
{
    /**
     * Run an orbit command on the gateway via SSH.
     *
     * SECURITY: Callers MUST use escapeshellarg() on all user-supplied values
     * before interpolating them into $orbitCommand. This method does not escape
     * the command itself — it is passed as-is to `orbit` on the remote host.
     *
     * Returns output even on non-zero exit codes (for JSON error responses).
     * Optional stdin data is piped to the remote command.
     */
    private function runOnGateway(Gateway $gateway, string $orbitCommand, ?string $stdin = null): ?string
    {
        $user = $gateway->ssh_user ?: 'orbit';
        $ip = $gateway->ip_address;

        if (preg_match('/[;|&`$]/', preg_replace("/'.+?'/", '', $orbitCommand) ?? '')) {
            throw new \InvalidArgumentException('Shell metacharacters detected in orbit command. Use escapeshellarg() on all user-supplied values.');
        }

        $remoteCmd = escapeshellarg(
            "export PATH=/home/linuxbrew/.linuxbrew/bin:\$HOME/.local/bin:/usr/local/bin:/usr/bin:/bin:\$PATH && orbit {$orbitCommand}",
        );

        $sshCmd = sprintf(
            'ssh -o ConnectTimeout=10 -o BatchMode=yes %s@%s %s',
            escapeshellarg((string) $user),
            escapeshellarg((string) $ip),
            $remoteCmd,
        );

        if ($stdin !== null) {
            $sshCmd = sprintf('echo %s | %s', escapeshellarg($stdin), $sshCmd);
        }

        $result = Process::timeout(20)->run($sshCmd);

        $output = trim($result->output());

        return $output !== '' ? $output : null;
    }
}
