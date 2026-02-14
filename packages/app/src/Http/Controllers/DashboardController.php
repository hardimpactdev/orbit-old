<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        // Web mode: redirect to node show page (injected by ImplicitNode middleware)
        if (! config('orbit.multi_node')) {
            $node = $request->route('node');

            if ($node instanceof Node) {
                return redirect()->route('nodes.show', $node);
            }

            // Fallback: find default node
            $node = Node::where('is_default', true)->first()
                ?? Node::first();

            if ($node) {
                return redirect()->route('nodes.show', $node);
            }

            // No node exists - redirect to sites page (will show empty state)
            return redirect()->route('nodes.sites');
        }

        // Desktop mode: redirect to default node
        $defaultNode = Node::getSelf();

        if ($defaultNode instanceof Node) {
            return redirect()->route('nodes.show', $defaultNode);
        }

        // No default node - check if any nodes exist
        $firstNode = Node::where('status', 'active')->first();

        if ($firstNode) {
            $firstNode->update(['is_default' => true]);

            return redirect()->route('nodes.show', $firstNode);
        }

        // No nodes at all - redirect to create
        return redirect()->route('nodes.create');
    }
}
