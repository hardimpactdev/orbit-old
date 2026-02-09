<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Concerns\HasStepOutput;
use App\Data\Install\InstallContext;
use App\Services\Install\InstallPipeline;
use App\Services\RemoteProvisioner;
use App\Services\TemplateRegistry;
use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Node;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\spin;

final class NodeProvisionCommand extends Command
{
    use HasStepOutput;

    protected $signature = 'node:provision
        {node_id : Node ID to provision}
        {--template= : Installation template (overrides inferred from node type)}
        {--services= : Docker services (comma-separated, e.g. postgres,redis)}
        {--php-versions=8.5 : PHP versions to install (comma-separated)}';

    protected $description = 'Provision a node using its configured template';

    public function handle(
        RemoteProvisioner $provisioner,
        TemplateRegistry $templates,
        InstallPipeline $pipeline,
    ): int {
        $this->warn('⚠️  DEPRECATED: This command will be removed in a future version.');
        $this->warn('   Use `orbit setup` instead for a better provisioning experience.');
        $this->newLine();

        $nodeId = (int) $this->argument('node_id');

        $node = Node::find($nodeId);
        if ($node === null) {
            $this->error("Node not found: {$nodeId}");

            return self::FAILURE;
        }

        if ($node->isLocalType()) {
            $this->error('Cannot provision local nodes remotely. Use "orbit install" instead.');

            return self::FAILURE;
        }

        $this->info("Provisioning node: {$node->name}");
        $this->line("  Host: {$node->host}");
        $this->line("  Type: {$node->node_type->value}");
        $this->newLine();

        $templateName = $this->option('template');
        if ($templateName === null) {
            $templateName = $this->inferTemplateFromNodeType($node->node_type);
        }

        if (! $templates->has($templateName)) {
            $this->error("Unknown template: {$templateName}");

            return self::FAILURE;
        }

        $template = $templates->get($templateName);

        $this->info("Template: {$template->label()}");
        $this->newLine();

        $provisioner->clearHostKey($node->host);

        $state = spin(
            fn () => $provisioner->detectState($node->host, 'root', $node->user),
            'Detecting server state...',
        );

        if (! $state['canConnectAsInitial'] && ! $state['canConnectAsRemote']) {
            $this->error('Cannot connect to server');
            $this->info("  Tried: root@{$node->host} and {$node->user}@{$node->host}");
            $this->info("  Fix: ssh-copy-id root@{$node->host}");

            return self::FAILURE;
        }

        $effectiveUser = $state['canConnectAsInitial'] ? 'root' : $node->user;
        $this->step("Connected as {$effectiveUser}");

        if ($effectiveUser === 'root') {
            $compatResult = spin(
                fn () => $provisioner->checkSystemCompatibility($node->host, $effectiveUser),
                'Checking system compatibility...',
            );

            if (! $compatResult['supported']) {
                $this->error('System not supported: ' . ($compatResult['error'] ?? 'Unknown'));

                return self::FAILURE;
            }

            $this->step("{$compatResult['os']} {$compatResult['version']} detected");
        }

        $phpVersions = array_map(
            'trim',
            explode(',', $this->option('php-versions'))
        );

        $services = $this->option('services')
            ? array_map('trim', explode(',', $this->option('services')))
            : $this->getDefaultServicesForNodeType($node->node_type);

        $context = InstallContext::fromOptions([
            'php-versions' => implode(',', $phpVersions),
            'services' => implode(',', $services),
            'node-type' => $node->node_type->value,
            'gateway-id' => $node->gateway_id,
            'node-name' => $node->name,
            'host-ip' => $node->host,
        ], $templateName);

        $this->newLine();
        $this->info('Starting installation...');
        $this->newLine();

        $result = $pipeline->run(
            template: $template,
            context: $context,
            osFamily: 'Linux',
            output: $this->output,
        );

        if (! $result->successful) {
            $this->newLine();
            $this->error('Provisioning failed: ' . $result->error);

            return self::FAILURE;
        }

        if (isset($context->metadata['vpn_ip'])) {
            $node->update([
                'vpn_ip' => $context->metadata['vpn_ip'],
                'gateway_id' => $context->gatewayId,
                'vpn_registered_at' => $context->metadata['vpn_registered_at'],
            ]);

            $this->newLine();
            $this->info("✓ VPN registered: {$context->metadata['vpn_ip']}");
        }

        $this->newLine();
        $this->info('✓ Node provisioned successfully');

        return self::SUCCESS;
    }

    private function inferTemplateFromNodeType(NodeType $type): string
    {
        return match ($type) {
            NodeType::Gateway => 'gateway',
            NodeType::Client => 'client',
            NodeType::Local => 'php-dev',
        };
    }

    /**
     * @return array<string>
     */
    private function getDefaultServicesForNodeType(NodeType $type): array
    {
        return match ($type) {
            NodeType::Gateway => ['redis', 'mailpit'],
            NodeType::Client => ['postgres', 'redis', 'mailpit'],
            NodeType::Local => ['postgres', 'redis', 'mailpit'],
        };
    }
}
