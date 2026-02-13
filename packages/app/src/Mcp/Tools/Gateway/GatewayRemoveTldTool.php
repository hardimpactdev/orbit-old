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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
final class GatewayRemoveTldTool extends Tool
{
    protected string $name = 'gateway_remove_tld';

    protected string $description = 'Remove a TLD DNS mapping from the gateway';

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
            'tld' => $schema->string('The TLD to remove (e.g. "dev")'),
        ])->required(['tld'])->toArray();
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'tld' => 'required|string|max:63',
        ]);

        $tld = $request->get('tld');
        $needsRestart = $this->dnsService->removeTldMapping($tld);

        if ($needsRestart) {
            $this->commandService->executeLocalCommand('service:restart dns');
        }

        return Response::structured([
            'success' => true,
            'removed_tld' => $tld,
            'dns_restarted' => $needsRestart,
        ]);
    }
}
