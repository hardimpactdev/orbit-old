<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\RemoteDeploy\RemoteCaddyManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class GatewayUpdateCaddyTool extends Tool
{
    protected string $name = 'gateway_update_caddy';

    protected string $description = 'Update existing .caddy files on a node with current security headers and security.txt';

    public function __construct(
        protected RemoteCaddyManager $caddyManager,
    ) {}

    public function shouldRegister(): bool
    {
        return Node::getSelf()?->isGateway() ?? false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_id' => $schema->integer()->required()->description('Target node ID'),
            'slug' => $schema->string()->description('Specific project slug to update, or omit for all'),
            'dry_run' => $schema->boolean()->description('Preview changes without applying (default: true)'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'node_id' => 'required|integer',
            'slug' => 'nullable|string|max:255',
            'dry_run' => 'nullable|boolean',
        ]);

        $node = Node::find($request->get('node_id'));

        if (! $node) {
            return Response::structured(['success' => false, 'error' => 'Node not found']);
        }

        if (! $node->isActive()) {
            return Response::structured(['success' => false, 'error' => 'Node is not active']);
        }

        $dryRun = $request->get('dry_run', true);
        $targetSlug = $request->get('slug');

        // Determine which slugs to update
        if ($targetSlug) {
            $slugs = [$targetSlug];
        } else {
            $listResult = $this->caddyManager->listSites($node);
            if (! $listResult['success']) {
                return Response::structured(['success' => false, 'error' => $listResult['error'] ?? 'Failed to list sites']);
            }
            $slugs = $listResult['slugs'] ?? [];
        }

        if (empty($slugs)) {
            return Response::structured(['success' => true, 'message' => 'No .caddy files found on this node']);
        }

        $results = [];
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($slugs as $slug) {
            $result = $this->caddyManager->update($node, $slug, $dryRun);
            $results[] = $result;

            if (! $result['success']) {
                $errors++;
            } elseif ($result['skipped'] ?? false) {
                $skipped++;
            } else {
                $updated++;
            }
        }

        // Reload Caddy once after all updates (only if not dry run and something changed)
        if (! $dryRun && $updated > 0) {
            $this->caddyManager->reloadOnNode($node);
        }

        return Response::structured([
            'success' => $errors === 0,
            'dry_run' => $dryRun,
            'summary' => [
                'total' => count($slugs),
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
            ],
            'results' => $results,
        ]);
    }
}
