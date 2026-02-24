<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools\Gateway;

use HardImpact\Orbit\Core\Models\GatewayProject;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\CloudflareService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class GatewayRegisterProjectTool extends Tool
{
    protected string $name = 'gateway_register_project';

    protected string $description = 'Register a gateway project to track deployments across nodes with optional Cloudflare zone auto-detection';

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
            'name' => $schema->string()->required()->description('Project display name'),
            'slug' => $schema->string()->description('URL-safe slug (auto-generated from name if omitted)'),
            'github_repo' => $schema->string()->description('GitHub repository (e.g. org/repo)'),
            'production_domain' => $schema->string()->description('Production domain (e.g. srpm.nl) — Cloudflare zone auto-detected'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'github_repo' => 'nullable|string|max:255',
            'production_domain' => 'nullable|string|max:255',
        ]);

        $slug = $request->get('slug') ?: Str::slug($request->get('name'));

        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            return Response::structured([
                'success' => false,
                'error' => "Invalid slug '{$slug}': must contain only lowercase alphanumeric characters and hyphens",
            ]);
        }

        if (GatewayProject::where('slug', $slug)->exists()) {
            return Response::structured([
                'success' => false,
                'error' => "Project with slug '{$slug}' already exists",
            ]);
        }

        $data = [
            'name' => $request->get('name'),
            'slug' => $slug,
            'github_repo' => $request->get('github_repo'),
            'production_domain' => $request->get('production_domain'),
        ];

        $productionDomain = $request->get('production_domain');
        if ($productionDomain) {
            $zone = $this->cloudflare->detectZoneForDomain($productionDomain);
            if ($zone) {
                $data['cloudflare_zone_id'] = $zone['zone_id'];
                $data['cloudflare_zone_name'] = $zone['zone_name'];
            }
        }

        $project = GatewayProject::create($data);

        $result = [
            'success' => true,
            'project' => [
                'id' => $project->id,
                'slug' => $project->slug,
                'name' => $project->name,
                'github_repo' => $project->github_repo,
                'production_domain' => $project->production_domain,
                'cloudflare_zone_id' => $project->cloudflare_zone_id,
                'cloudflare_zone_name' => $project->cloudflare_zone_name,
            ],
        ];

        return Response::structured($result);
    }
}
