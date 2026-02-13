<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Services\Gateway\GatewayDnsService;
use HardImpact\Orbit\Core\Services\Gateway\WgEasyService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class GatewayCreateClientTool extends Tool
{
    protected string $name = 'gateway_create_client';

    protected string $description = 'Create a new VPN client and optionally assign a TLD mapping';

    public function __construct(
        protected GatewayDnsService $dnsService,
        protected CommandService $commandService,
    ) {}

    public function shouldRegister(): bool
    {
        $node = Node::getSelf();

        return $node?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return $schema->object([
            'name' => $schema->string('Name for the VPN client'),
            'tld' => $schema->string('Optional TLD to assign to this client (e.g. "dev")'),
        ])->required(['name'])->toArray();
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'tld' => 'nullable|string|max:63',
        ]);

        $name = $request->get('name');
        $tld = $request->get('tld');

        $password = Setting::get('wg_easy_password');
        $host = Setting::get('wg_easy_host', '127.0.0.1');
        $port = (int) Setting::get('wg_easy_port', '51821');

        if (! $password) {
            return Response::structured([
                'success' => false,
                'error' => 'WG Easy password not configured.',
            ]);
        }

        $wgService = WgEasyService::forGateway($host, $port, $password);
        $client = $wgService->createClient($name);

        if ($client === null) {
            return Response::structured([
                'success' => false,
                'error' => 'Failed to create VPN client. Check WG Easy is running.',
            ]);
        }

        $result = [
            'success' => true,
            'client' => $client,
        ];

        if ($tld !== null) {
            $ip = str_replace('/32', '', $client['ip']);
            $needsRestart = $this->dnsService->addTldMapping($tld, $ip);
            $result['tld_mapping'] = ['tld' => $tld, 'ip' => $ip];

            if ($needsRestart) {
                $node = Node::getSelf();
                if ($node) {
                    $this->commandService->executeLocalCommand('service:restart dns');
                }
                $result['dns_restarted'] = true;
            }
        }

        return Response::structured($result);
    }
}
