<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\Gateway\GatewayDnsService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class GatewayAddTldTool extends Tool
{
    protected string $name = 'gateway_add_tld';

    protected string $description = 'Add a DNS mapping to route a TLD to a VPN client IP';

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
        return [
            'tld' => $schema->string()->required()->description('The TLD to route (e.g. "dev", "staging")'),
            'ip' => $schema->string()->required()->description('The VPN IP to route traffic to (e.g. "10.8.0.2")'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'tld' => 'required|string|max:63',
            'ip' => 'required|ip',
        ]);

        $tld = $request->get('tld');
        $ip = $request->get('ip');

        $needsRestart = $this->dnsService->addTldMapping($tld, $ip);

        if ($needsRestart) {
            $this->commandService->executeLocalCommand('service:restart dns');
        }

        return Response::structured([
            'success' => true,
            'mapping' => ['tld' => $tld, 'ip' => $ip],
            'dns_restarted' => $needsRestart,
        ]);
    }
}
