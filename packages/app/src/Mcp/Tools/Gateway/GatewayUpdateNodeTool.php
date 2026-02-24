<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Enums\NodeEnvironment;
use HardImpact\Orbit\Core\Enums\NodeStatus;
use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class GatewayUpdateNodeTool extends Tool
{
    protected string $name = 'gateway_update_node';

    protected string $description = 'Update a node\'s properties (host, external_host, name, environment, status, is_active, user, port)';

    public function shouldRegister(): bool
    {
        return Node::getSelf()?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_id' => $schema->integer()->required()->description('The node ID to update'),
            'name' => $schema->string()->description('New node name'),
            'host' => $schema->string()->description('New host (IP address or hostname)'),
            'external_host' => $schema->string()->description('New external host (public IP or hostname for Cloudflare DNS)'),
            'user' => $schema->string()->description('SSH user'),
            'port' => $schema->integer()->description('SSH port'),
            'environment' => $schema->string()->description('Node environment: development, staging, or production'),
            'status' => $schema->string()->description('Node status: active, provisioning, or error'),
            'is_active' => $schema->boolean()->description('Whether this is the currently selected node'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'node_id' => 'required|integer',
            'name' => 'sometimes|string|max:255',
            'host' => 'sometimes|string|max:255',
            'external_host' => 'sometimes|nullable|string|max:255',
            'user' => 'sometimes|string|max:255',
            'port' => 'sometimes|integer|min:1|max:65535',
            'environment' => 'sometimes|string|in:development,staging,production',
            'status' => 'sometimes|string|in:active,provisioning,error',
            'is_active' => 'sometimes|boolean',
        ]);

        $node = Node::find($request->get('node_id'));

        if ($node === null) {
            return Response::structured([
                'success' => false,
                'error' => 'Node not found',
            ]);
        }

        $updates = [];
        $fields = ['name', 'host', 'external_host', 'user', 'port', 'is_active'];

        foreach ($fields as $field) {
            $value = $request->get($field);
            if ($value !== null) {
                $updates[$field] = $value;
            }
        }

        if ($request->get('environment') !== null) {
            $environment = NodeEnvironment::tryFrom($request->get('environment'));
            if ($environment === null) {
                return Response::structured([
                    'success' => false,
                    'error' => 'Invalid environment. Must be: development, staging, or production',
                ]);
            }
            $updates['environment'] = $environment;
        }

        if ($request->get('status') !== null) {
            $status = NodeStatus::tryFrom($request->get('status'));
            if ($status === null) {
                return Response::structured([
                    'success' => false,
                    'error' => 'Invalid status. Must be: active, provisioning, or error',
                ]);
            }
            $updates['status'] = $status;
        }

        if (empty($updates)) {
            return Response::structured([
                'success' => false,
                'error' => 'No fields to update. Provide at least one of: name, host, external_host, user, port, environment, status, is_active',
            ]);
        }

        try {
            $node->update($updates);
        } catch (\InvalidArgumentException $e) {
            return Response::structured([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }

        $node->refresh();

        return Response::structured([
            'success' => true,
            'updated_fields' => array_keys($updates),
            'node' => [
                'id' => $node->id,
                'name' => $node->name,
                'host' => $node->host,
                'external_host' => $node->external_host,
                'user' => $node->user,
                'port' => $node->port,
                'environment' => $node->environment->value,
                'status' => $node->status->value,
                'is_active' => $node->is_active,
                'tld' => $node->tld,
                'vpn_ip' => $node->vpn_ip,
            ],
        ]);
    }
}
