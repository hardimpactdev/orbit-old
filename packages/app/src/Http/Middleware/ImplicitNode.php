<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Middleware;

use Closure;
use HardImpact\Orbit\Core\Services\NodeManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ImplicitNode
{
    public function __construct(protected NodeManager $nodes) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Only active in web mode (multi_node=false)
        if (config('orbit.multi_node')) {
            return $next($request);
        }

        $route = $request->route();
        if (! $route) {
            return $next($request);
        }

        // If node is already in the route, we don't need to inject it
        if ($route->hasParameter('node')) {
            return $next($request);
        }

        // Also skip if it's an explicit node route that hasn't bound yet
        if (str_starts_with($route->uri(), 'nodes/{node}')) {
            return $next($request);
        }

        $node = $this->nodes->current();

        if (! $node) {
            abort(500, 'No node found. Run: php artisan orbit:init');
        }

        // Warn if multiple is_default nodes exist
        $count = \HardImpact\Orbit\Core\Models\Node::where('is_default', true)->count();
        if ($count > 1) {
            Log::warning("Multiple is_default=true nodes found ({$count}). Using first.");
        }

        // Inject into route so controllers receive it as parameter
        $params = $route->parameters();
        foreach (array_keys($params) as $key) {
            $route->forgetParameter($key);
        }

        $route->setParameter('node', $node);

        foreach ($params as $key => $value) {
            $route->setParameter($key, $value);
        }

        return $next($request);
    }
}
