<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\HasStepOutput;
use App\Services\GatewayManager;
use App\Services\IpValidator;
use App\Services\RemoteProvisioner;
use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Node;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

final class SetupRemoteCommand extends Command
{
    use HasStepOutput;

    protected $signature = 'setup:remote
                            {ip? : Public IP address of the target server}
                            {user? : Root user to log in as (e.g., root, ubuntu)}
                            {--remote-user=orbit : Username to create on remote server}
                            {--template=php-dev : Installation template}
                            {--services= : Docker services (comma-separated, e.g. postgres,redis)}
                            {--node-packages= : Node package managers (comma-separated: npm,yarn,pnpm,bun)}
                            {--binary= : Path to local phar binary to copy instead of downloading}
                            {--gateway= : Gateway ID for client nodes (for VPN registration)}
                            {--skip-hardening : Skip SSH hardening and user creation}
                            {--yes : Skip confirmation prompts}';

    protected $description = 'Provision a remote Linux server and install an Orbit template';

    private const array DOCKER_ALWAYS_TEMPLATES = ['gateway', 'php-dev'];

    public function handle(RemoteProvisioner $provisioner, GatewayManager $gatewayManager): int
    {
        if (PHP_OS_FAMILY !== 'Linux' && PHP_OS_FAMILY !== 'Darwin') {
            $this->error('This command must be run from Linux or macOS');

            return self::FAILURE;
        }

        $template = $this->option('template');
        $services = $this->option('services');

        $this->info('Remote Server Setup');
        $this->newLine();
        $this->warn("This will provision a remote Linux server and install the {$template} template.");
        $this->line('Requirements:');
        $this->line('  - Target server is running Linux');
        $this->line('  - SSH key is configured on the server');
        $this->line('  - SSH access is configured in ~/.ssh/config');
        $this->line('  - You have sudo/root access on the target');
        $this->newLine();

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

        $validationError = $ipValidator->validate($ip);
        if ($validationError !== null) {
            $this->error("Invalid IP address: {$ip}");
            $this->error($validationError);

            return self::FAILURE;
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
        if ($remoteUser === 'orbit' && ! $this->option('yes')) {
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

        if (! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $remoteUser)) {
            $this->error("Invalid remote username: {$remoteUser}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Target: {$user}@{$ip}");
        $this->info("Remote user: {$remoteUser}");
        $this->info("Template: {$template}");
        $this->newLine();

        $provisioner->clearHostKey($ip);

        $state = spin(
            fn () => $provisioner->detectState($ip, $user, $remoteUser),
            'Detecting server state...',
        );

        $effectiveUser = null;

        if ($state['canConnectAsInitial']) {
            $effectiveUser = $user;
            $this->step("Connected as {$user}");
        } elseif ($state['canConnectAsRemote']) {
            $effectiveUser = $remoteUser;
            $this->step("Connected as {$remoteUser} (resuming partial setup)");
        } else {
            $this->error('Cannot connect to server');
            $this->info("  Tried: {$user}@{$ip} and {$remoteUser}@{$ip}");
            $this->info("  Fix: ssh-copy-id {$user}@{$ip}");

            return self::FAILURE;
        }

        if ($effectiveUser === $user) {
            $compatResult = spin(
                fn () => $provisioner->checkSystemCompatibility($ip, $effectiveUser),
                'Checking system compatibility...',
            );

            if (! $compatResult['supported']) {
                $this->error('System not supported: '.($compatResult['error'] ?? 'Unknown'));

                return self::FAILURE;
            }

            $this->step("{$compatResult['os']} {$compatResult['version']} detected");
        }

        if ($this->option('skip-hardening')) {
            $this->skip('Skipping user creation and SSH hardening (--skip-hardening)');
        } else {
            if (! $state['userExists'] && $effectiveUser === $user) {
                $created = spin(
                    fn () => $provisioner->createUser($ip, $user, $remoteUser),
                    "Creating {$remoteUser} user...",
                );

                if (! $created) {
                    $this->error("Failed to create {$remoteUser} user");

                    return self::FAILURE;
                }
                $this->step("{$remoteUser} user created with sudo access");
            } else {
                $this->step("{$remoteUser} user already exists");
            }

            if ($effectiveUser === $user) {
                $copied = spin(
                    fn () => $provisioner->copySshKeys($ip, $user, $remoteUser),
                    'Copying SSH authorized_keys...',
                );

                if (! $copied) {
                    $this->error('Failed to copy SSH keys');
                    $this->info("  Fix: ssh-copy-id {$user}@{$ip}");

                    return self::FAILURE;
                }
                $this->step('SSH keys copied to '.$remoteUser);
            }

            if (! $state['sshHardened'] && $effectiveUser === $user) {
                $hardened = spin(
                    fn () => $provisioner->hardenSsh($ip, $user),
                    'Hardening SSH configuration...',
                );

                if (! $hardened) {
                    $this->error('Failed to harden SSH');

                    return self::FAILURE;
                }
                $this->step('SSH hardened (root login disabled, password auth disabled)');

                $remoteUserConnection = spin(
                    fn () => $provisioner->testConnection($ip, $remoteUser),
                    "Verifying {$remoteUser} SSH access...",
                );

                if (! $remoteUserConnection['success']) {
                    $this->error("Cannot connect as {$remoteUser} after SSH hardening");

                    return self::FAILURE;
                }
                $this->step("{$remoteUser} SSH access verified");

                $effectiveUser = $remoteUser;
            } else {
                $this->step('SSH already hardened');
            }
        }

        $updateResult = spin(
            fn () => $provisioner->updateSystem($ip, $effectiveUser),
            'Updating system packages...',
        );

        if ($updateResult['success']) {
            $this->step('System packages updated');
        } else {
            $this->skip('System update failed, continuing');
        }

        $nodeType = $this->inferNodeTypeFromTemplate($template);

        // Client nodes don't need Orbit CLI - they're managed remotely by the gateway
        if ($nodeType !== NodeType::Client) {
            if (! $state['orbitInstalled']) {
                $localBinary = $this->option('binary');

                if ($localBinary === null && file_exists('builds/orbit.phar')) {
                    $localBinary = 'builds/orbit.phar';
                }

                $installResult = spin(
                    fn () => $provisioner->installOrbit($ip, $remoteUser, $localBinary),
                    'Installing Orbit CLI...',
                );

                if (! $installResult['success']) {
                    $this->error('Failed to install Orbit CLI');
                    $this->warn($installResult['error'] ?? 'Unknown error');

                    return self::FAILURE;
                }
                $this->step('Orbit CLI installed');
            } else {
                $this->step('Orbit CLI already installed');
            }
        } else {
            $this->step('Skipping Orbit CLI installation for client node');
        }

        $needsDocker = $this->templateNeedsDocker($template, $services);

        if ($needsDocker) {
            $dockerResult = spin(
                fn () => $provisioner->installDocker($ip, $remoteUser),
                'Installing Docker...',
            );

            if (! $dockerResult['success']) {
                $this->error('Failed to install Docker');
                $this->warn($dockerResult['error'] ?? 'Unknown error');

                return self::FAILURE;
            }
            $this->step('Docker ready');
        }

        // Client nodes don't need Orbit CLI or database - they're managed remotely
        if ($nodeType !== NodeType::Client) {
            $migrateResult = spin(
                fn () => $provisioner->runMigrations($ip, $remoteUser),
                'Running database migrations...',
            );

            if (! $migrateResult['success']) {
                $this->error('Failed to run migrations');
                $this->warn($migrateResult['error'] ?? 'Unknown error');

                return self::FAILURE;
            }
            $this->step('Database migrations complete');
        } else {
            $this->step('Skipping database setup for client node');
        }

        // Client nodes don't need template installation - they're managed remotely
        if ($nodeType !== NodeType::Client) {
            $templateOptions = [];
            if ($services !== null && $services !== '') {
                $templateOptions['services'] = $services;
            }

            $nodePackages = $this->option('node-packages');
            if ($nodePackages !== null && $nodePackages !== '') {
                $templateOptions['node-packages'] = $nodePackages;
            }

            // Install template using the install pipeline
            $templateResult = spin(
                fn () => $provisioner->installTemplate($ip, $remoteUser, $template, $templateOptions),
                "Installing {$template} template...",
            );

            if (! $templateResult['success']) {
                $this->error("Failed to install {$template} template");
                $this->warn($templateResult['error'] ?? 'Unknown error');
                $this->info("  Retry: ssh {$remoteUser}@{$ip} orbit install --template={$template}");

                return self::FAILURE;
            }
            $this->step("{$template} template installed");
        } else {
            $this->step('Skipping template installation for client node');
        }

        $gatewayId = $this->option('gateway') ? (int) $this->option('gateway') : null;

        $node = Node::updateOrCreate(
            ['host' => $ip],
            [
                'name' => $ip,
                'user' => $remoteUser,
                'port' => 22,
                'node_type' => $nodeType,
                'gateway_id' => $gatewayId,
            ]
        );

        if ($nodeType === NodeType::Client && $gatewayId !== null) {
            $vpnResult = spin(
                fn () => $this->registerWithVpn($node, $gatewayManager),
                'Registering with gateway VPN...',
            );

            if ($vpnResult['success']) {
                $this->step("VPN registered: {$vpnResult['vpn_ip']}");
            } else {
                $this->skip('VPN registration failed, continuing');
            }
        }

        $this->newLine();
        $this->line('<fg=green;options=bold>Remote setup complete!</>');
        $this->newLine();
        $this->line("  <fg=gray>SSH</>       ssh {$remoteUser}@{$ip}");
        $this->line("  <fg=gray>Template</>  {$template}");
        $this->line("  <fg=gray>Node Type</> {$nodeType->value}");
        if ($node->hasVpn()) {
            $this->line("  <fg=gray>VPN IP</>    {$node->getAttribute('vpn_ip')}");
        }
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return array{success: bool, vpn_ip?: string, error?: string}
     */
    private function registerWithVpn(Node $node, GatewayManager $gatewayManager): array
    {
        try {
            $gatewayId = $node->getAttribute('gateway_id');
            if ($gatewayId === null) {
                return ['success' => false, 'error' => 'No gateway configured'];
            }

            $vpnIp = $gatewayManager->registerVpnClient(
                $gatewayId,
                $node->name,
            );

            if ($vpnIp === null) {
                return ['success' => false, 'error' => 'VPN registration failed'];
            }

            $node->update([
                'vpn_ip' => $vpnIp,
                'vpn_registered_at' => now(),
            ]);

            return ['success' => true, 'vpn_ip' => $vpnIp];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function inferNodeTypeFromTemplate(string $template): NodeType
    {
        return match ($template) {
            'gateway' => NodeType::Gateway,
            'client' => NodeType::Client,
            default => NodeType::Local,
        };
    }

    private function templateNeedsDocker(string $template, ?string $services): bool
    {
        if (in_array($template, self::DOCKER_ALWAYS_TEMPLATES, true)) {
            return true;
        }

        return $services !== null && $services !== '';
    }
}
