<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\HasStepOutput;
use App\Services\IpValidator;
use App\Services\RemoteProvisioner;
use HardImpact\Orbit\Core\Models\Gateway;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\text;

/**
 * Set up a fresh gateway instance on a remote Linux server.
 *
 * Delegates server provisioning to setup:remote, then handles
 * gateway-specific post-install (WG password, DB record, summary).
 */
final class SetupGatewayCommand extends Command
{
    use HasStepOutput;

    protected $signature = 'setup:gateway
                            {ip? : Public IP address of the target server}
                            {user? : Root user to log in as (e.g., root, ubuntu)}
                            {--remote-user=orbit : Username to create on remote server (default: orbit)}
                            {--binary= : Path to local phar binary to copy instead of downloading}
                            {--yes : Skip confirmation prompts}';

    protected $description = 'Set up a fresh gateway instance on a remote Linux server';

    public function handle(RemoteProvisioner $provisioner): int
    {
        $ipValidator = app(IpValidator::class);

        $ip = $this->argument('ip');
        if ($ip === null) {
            $ip = text(
                label: 'Public IP address of the target server',
                placeholder: 'e.g., 203.0.113.10',
                required: true,
                validate: fn ($value) => $ipValidator->validate($value),
            );
        }

        $user = $this->argument('user');
        if ($user === null) {
            $user = text(
                label: 'SSH user to log in as',
                placeholder: 'e.g., root, ubuntu, admin',
                default: 'root',
                required: true,
            );
        }

        $remoteUser = $this->option('remote-user');

        $args = array_filter([
            'ip' => $ip,
            'user' => $user,
            '--remote-user' => $remoteUser,
            '--template' => 'gateway',
            '--binary' => $this->option('binary'),
            '--yes' => true,
        ], fn ($v) => $v !== null);

        $result = $this->call('setup:remote', $args);

        if ($result !== self::SUCCESS) {
            return $result;
        }

        $wgPassword = $provisioner->getWgPassword($ip, $remoteUser);

        if ($wgPassword) {
            $escapedPassword = escapeshellarg($wgPassword);
            $storeResult = $provisioner->sshRun(
                $ip,
                $remoteUser,
                "export PATH=/home/linuxbrew/.linuxbrew/bin:\$HOME/.local/bin:/usr/local/bin:/usr/bin:/bin:\$PATH && orbit gateway:set-password {$escapedPassword}",
            );

            if ($storeResult) {
                $this->step('Password stored on gateway');
            } else {
                $this->skip('Could not store password on gateway (run manually: orbit gateway:set-password)');
            }
        }

        $existingGateway = Gateway::where('ip_address', $ip)->first();

        if ($existingGateway) {
            $existingGateway->update([
                'status' => 'active',
                'wg_password' => $wgPassword,
                'last_connected_at' => now(),
            ]);
        } else {
            Gateway::create([
                'name' => "gateway-{$ip}",
                'ip_address' => $ip,
                'ssh_user' => $remoteUser,
                'status' => 'active',
                'wg_password' => $wgPassword,
                'last_connected_at' => now(),
            ]);
        }

        $this->newLine();
        $this->line('<fg=green;options=bold>Gateway setup complete!</>');
        $this->newLine();
        $this->line('  <fg=gray>SSH</>       ssh '.$remoteUser.'@'.$ip);
        $this->line('  <fg=gray>Web UI</>    http://'.$ip.':51821');
        if ($wgPassword) {
            $this->line('  <fg=gray>Password</>  '.$wgPassword);
        }
        $this->newLine();
        $this->line('<fg=yellow>Next steps:</>');
        $this->line('  1. Open the Web UI at <fg=cyan>http://'.$ip.':51821</>');
        if ($wgPassword) {
            $this->line('  2. Log in with the password above');
        } else {
            $this->line("  2. Get password: <fg=cyan>ssh {$remoteUser}@{$ip} 'cat ~/.config/orbit/config.json'</> | grep password");
        }
        $this->line('  3. Create VPN clients for your devices');

        return self::SUCCESS;
    }
}
