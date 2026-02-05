<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\Gateway;
use App\Services\IpValidator;
use App\Services\RemoteProvisioner;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\text;

/**
 * Set up a fresh gateway instance on a remote Linux server.
 *
 * This command relies on your SSH config (~/.ssh/config) for authentication.
 * Make sure you have proper SSH access configured before running.
 */
final class SetupGatewayCommand extends Command
{
    protected $signature = 'setup:gateway
                            {ip? : Public IP address of the target server}
                            {user? : Root user to log in as (e.g., root, ubuntu)}
                            {--remote-user=orbit : Username to create on remote server (default: orbit)}
                            {--binary= : Path to local phar binary to copy instead of downloading}
                            {--yes : Skip confirmation prompts}';

    protected $description = 'Set up a fresh gateway instance on a remote Linux server';

    public function handle(RemoteProvisioner $provisioner): int
    {
        // Ensure we're on Linux or macOS
        if (PHP_OS_FAMILY !== 'Linux' && PHP_OS_FAMILY !== 'Darwin') {
            $this->error('This command must be run from Linux or macOS');

            return self::FAILURE;
        }

        $this->info('Gateway Remote Setup');
        $this->newLine();
        $this->warn('This will set up a fresh gateway instance on a remote Linux server.');
        $this->line('Requirements:');
        $this->line('  - Target server is running Linux');
        $this->line('  - SSH key is configured on the server');
        $this->line('  - SSH access is configured in ~/.ssh/config');
        $this->line('  - You have sudo/root access on the target');
        $this->newLine();

        $ipValidator = app(IpValidator::class);

        // Get IP address
        $ip = $this->argument('ip');
        if ($ip === null) {
            $ip = text(
                label: 'Public IP address of the target server',
                placeholder: 'e.g., 203.0.113.10',
                required: true,
                validate: fn ($value) => $ipValidator->validate($value),
            );
        }

        $validationError = $ipValidator->validate($ip);
        if ($validationError !== null) {
            $this->error("Invalid IP address: {$ip}");
            $this->error($validationError);

            return self::FAILURE;
        }

        // Get SSH user
        $user = $this->argument('user');
        if ($user === null) {
            $user = text(
                label: 'SSH user to log in as',
                placeholder: 'e.g., root, ubuntu, admin',
                default: 'root',
                required: true,
            );
        }

        // Get remote user to create
        $remoteUser = $this->option('remote-user');
        if ($remoteUser === 'orbit') {
            // Only prompt if default value wasn't overridden via --remote-user
            $remoteUser = text(
                label: 'Remote user to create on server',
                placeholder: 'e.g., orbit, deploy, admin',
                default: 'orbit',
                required: true,
                validate: function ($value) {
                    if (! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $value)) {
                        return 'Invalid Linux username. Must start with letter/underscore, contain only lowercase letters, numbers, underscores, hyphens (1-32 chars).';
                    }

                    return null;
                },
            );
        }

        // Validate remote user name (must be valid Linux username)
        if (! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $remoteUser)) {
            $this->error("Invalid remote username: {$remoteUser}");
            $this->info('Username must:');
            $this->info('  - Start with a lowercase letter or underscore');
            $this->info('  - Contain only lowercase letters, numbers, underscores, and hyphens');
            $this->info('  - Be 1-32 characters long');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Target: {$user}@{$ip}");
        $this->info("Remote user to create: {$remoteUser}");
        $this->newLine();

        // Clear stale host keys before first connection (fresh provisioning)
        $provisioner->clearHostKey($ip);

        $this->info('Detecting server state...');
        $state = $provisioner->detectState($ip, $user, $remoteUser);

        // Determine effective user (which user to run commands as)
        $effectiveUser = null;

        if ($state['canConnectAsInitial']) {
            $effectiveUser = $user;
            $this->info("✓ Can connect as initial user ({$user})");
        } elseif ($state['canConnectAsRemote']) {
            $effectiveUser = $remoteUser;
            $this->info("✓ Can connect as remote user ({$remoteUser}) - continuing from partial setup");
            $this->warn('  Initial user no longer accessible (SSH hardened)');
        } else {
            $this->error('Cannot connect to server');
            $this->info('Tried both:');
            $this->info("  - {$user}@{$ip} (initial user)");
            $this->info("  - {$remoteUser}@{$ip} (remote user)");
            $this->newLine();
            $this->info('Make sure your SSH key is added:');
            $this->info("  ssh-copy-id {$user}@{$ip}");

            return self::FAILURE;
        }

        // Show detected state
        if ($state['userExists']) {
            $this->info("✓ Remote user '{$remoteUser}' already exists");
        }
        if ($state['sshHardened']) {
            $this->info('✓ SSH already hardened');
        }
        if ($state['orbitInstalled']) {
            $this->info('✓ Orbit CLI already installed');
        }
        $this->newLine();

        // Check if everything is already done
        $isComplete = $state['userExists'] && $state['sshHardened'] && $state['orbitInstalled'];
        if ($isComplete) {
            $this->info('Server already configured, proceeding...');
            $this->newLine();
        }

        // Check system compatibility (if we have root access)
        if ($effectiveUser === $user) {
            $this->info('Checking system compatibility...');
            $compatResult = $provisioner->checkSystemCompatibility($ip, $effectiveUser);

            if (! $compatResult['supported']) {
                $this->error('System not supported');
                $this->warn($compatResult['error'] ?? 'Unknown compatibility error');

                return self::FAILURE;
            }

            $this->info("✓ {$compatResult['os']} {$compatResult['version']} detected");
            $this->newLine();
        }

        // Step 1: Create remote user (skip if exists and we're using root)
        if (! $state['userExists'] && $effectiveUser === $user) {
            $this->info("Step 1: Creating {$remoteUser} user...");
            if (! $provisioner->createUser($ip, $user, $remoteUser)) {
                $this->error("Failed to create {$remoteUser} user");

                return self::FAILURE;
            }
            $this->info("✓ {$remoteUser} user created");
            $this->newLine();
        } elseif ($state['userExists']) {
            $this->info("Step 1: Skipped ({$remoteUser} user already exists)");
            $this->newLine();
        }

        // Step 2: Copy SSH keys (skip if connecting as remote user already works)
        if ($effectiveUser === $user) {
            $this->info('Step 2: Copying SSH authorized_keys...');
            if (! $provisioner->copySshKeys($ip, $user, $remoteUser)) {
                $this->error('Failed to copy SSH keys');
                $this->info('You may need to manually copy your SSH key to the server first:');
                $this->info("  ssh-copy-id {$user}@{$ip}");

                return self::FAILURE;
            }
            $this->info('✓ SSH keys copied');
            $this->newLine();
        } else {
            $this->info('Step 2: Skipped (using existing remote user connection)');
            $this->newLine();
        }

        // Step 3: Harden SSH (skip if already hardened)
        if (! $state['sshHardened'] && $effectiveUser === $user) {
            $this->info('Step 3: Hardening SSH configuration...');
            if (! $provisioner->hardenSsh($ip, $user)) {
                $this->error('Failed to harden SSH');

                return self::FAILURE;
            }
            $this->info('✓ SSH hardened (password auth disabled)');
            $this->newLine();

            // After hardening, switch to remote user for remaining steps
            $this->info("Verifying {$remoteUser} user SSH access...");
            $remoteUserConnection = $provisioner->testConnection($ip, $remoteUser);

            if (! $remoteUserConnection['success']) {
                $this->error("Cannot connect as {$remoteUser} user after SSH hardening");
                $this->warn($remoteUserConnection['error'] ?? 'Unknown error');
                $this->info('SSH key may not have been copied correctly.');

                return self::FAILURE;
            }

            $this->info("✓ {$remoteUser} user SSH access verified");
            $this->newLine();
            $effectiveUser = $remoteUser;
        } elseif ($state['sshHardened']) {
            $this->info('Step 3: Skipped (SSH already hardened)');
            $this->newLine();
        }

        // Step 4: Install Orbit CLI on remote (skip if installed)
        if (! $state['orbitInstalled']) {
            $this->info('Step 4: Installing Orbit CLI on remote server...');

            // Determine which binary to use
            $localBinary = $this->option('binary');

            // Auto-detect local phar if not explicitly provided
            if ($localBinary === null && file_exists('builds/orbit.phar')) {
                $localBinary = 'builds/orbit.phar';
                $this->info('   Using local phar: builds/orbit.phar');
            } elseif ($localBinary !== null) {
                $this->info("   Using local phar: {$localBinary}");
            }

            $installResult = $provisioner->installOrbit($ip, $remoteUser, $localBinary);

            if (! $installResult['success']) {
                $this->error('Failed to install Orbit CLI');
                $this->warn($installResult['error'] ?? 'Unknown error');

                return self::FAILURE;
            }
            $this->info('✓ Orbit CLI installed');
            $this->newLine();
        } else {
            $this->info('Step 4: Skipped (Orbit CLI already installed)');
            $this->newLine();
        }

        // Step 5: Run gateway install
        $this->info('Step 5: Setting up gateway stack...');
        $this->info('   This may take a few minutes...');
        $this->newLine();

        $result = $provisioner->setupGateway($ip, $remoteUser);

        if (! $result) {
            $this->error('Failed to set up gateway stack');
            $this->info('You can retry by SSHing in and running: orbit install --template=gateway');

            return self::FAILURE;
        }

        // Check if gateway already exists in database
        $existingGateway = Gateway::where('ip_address', $ip)->first();

        if ($existingGateway) {
            $existingGateway->update([
                'status' => 'active',
                'last_connected_at' => now(),
            ]);
            $gateway = $existingGateway;
            $this->info('Updated existing gateway record');
        } else {
            $gateway = Gateway::create([
                'name' => "gateway-{$ip}",
                'ip_address' => $ip,
                'ssh_user' => $remoteUser,
                'status' => 'active',
                'last_connected_at' => now(),
            ]);
        }

        $this->newLine();
        $this->info('Gateway setup complete!');
        $this->newLine();

        // Get WG Easy password from remote config via JSON parsing
        $escapedRemoteUser = escapeshellarg($remoteUser);
        $escapedIp = escapeshellarg($ip);
        $passwordResult = Process::run("ssh {$escapedRemoteUser}@{$escapedIp} 'cat ~/.config/orbit/config.json 2>/dev/null'");
        $wgPassword = null;
        if ($passwordResult->successful()) {
            $configData = json_decode(trim($passwordResult->output()), true);
            $wgPassword = $configData['wg_easy']['password'] ?? $configData['password'] ?? null;
        }

        $this->line('<fg=green>Connection Details:</>');
        $this->line("  SSH: ssh {$remoteUser}@{$ip}");
        $this->line("  Web UI: http://{$ip}:51821 (WG Easy)");
        if ($wgPassword) {
            $this->newLine();
            $this->line('<fg=cyan>WG Easy Admin Password:</>');
            $this->line("  {$wgPassword}");
        }
        $this->newLine();
        $this->line('<fg=yellow>Next steps:</>');
        $this->line("1. SSH into the server: ssh {$remoteUser}@{$ip}");
        $this->line('2. Open Web UI: http://'.$ip.':51821');
        if ($wgPassword) {
            $this->line('3. Log in with the password above');
            $this->line('4. Create VPN clients for your devices');
        } else {
            $this->line('3. Create VPN clients: orbit gateway:make:client');
        }
        $this->newLine();
        $this->info("Gateway saved to database (ID: {$gateway->id})");

        return self::SUCCESS;
    }
}
