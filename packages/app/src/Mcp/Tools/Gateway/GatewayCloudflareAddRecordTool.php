<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\CloudflareService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class GatewayCloudflareAddRecordTool extends Tool
{
    protected string $name = 'gateway_cloudflare_add_record';

    protected string $description = 'Add a DNS record to Cloudflare';

    public function __construct(
        protected CloudflareService $cloudflare,
    ) {}

    public function shouldRegister(): bool
    {
        return Node::getSelf()?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Record name (e.g. myapp.example.com)'),
            'content' => $schema->string()->required()->description('Record value (e.g. IP address for A records)'),
            'type' => $schema->string()->description('Record type: A, AAAA, CNAME, TXT (default: A)'),
            'proxied' => $schema->boolean()->description('Whether to proxy through Cloudflare (default: true)'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'name' => 'required|string',
            'content' => 'required|string',
            'type' => 'nullable|string|in:A,AAAA,CNAME,TXT,MX',
            'proxied' => 'nullable|boolean',
        ]);

        if (! $this->cloudflare->isConfigured()) {
            return Response::structured([
                'success' => false,
                'error' => 'Cloudflare not configured. Set cloudflare_api_token and cloudflare_zone_id in settings.',
            ]);
        }

        $record = $this->cloudflare->createRecord(
            name: $request->get('name'),
            content: $request->get('content'),
            type: $request->get('type', 'A'),
            proxied: $request->get('proxied', true),
        );

        if (! $record) {
            return Response::structured(['success' => false, 'error' => 'Failed to create DNS record']);
        }

        return Response::structured([
            'success' => true,
            'record' => [
                'id' => $record['id'],
                'name' => $record['name'],
                'type' => $record['type'],
                'content' => $record['content'],
                'proxied' => $record['proxied'] ?? false,
            ],
        ]);
    }
}
