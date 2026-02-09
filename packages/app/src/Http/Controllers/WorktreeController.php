<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\OrbitCli\WorktreeService;
use Illuminate\Http\Request;

class WorktreeController extends Controller
{
    public function __construct(
        protected WorktreeService $worktree,
    ) {}

    /**
     * Get all worktrees for a node.
     */
    public function index(Node $node)
    {
        $result = $this->worktree->worktrees($node);

        return response()->json($result);
    }

    /**
     * Unlink a worktree from a project.
     */
    public function unlink(Request $request, Node $node)
    {
        $validated = $request->validate([
            'project' => 'required|string',
            'worktree' => 'required|string',
        ]);

        $result = $this->worktree->unlinkWorktree(
            $node,
            $validated['project'],
            $validated['worktree']
        );

        return response()->json($result);
    }

    /**
     * Refresh worktree detection.
     */
    public function refresh(Node $node)
    {
        $result = $this->worktree->refreshWorktrees($node);

        return response()->json($result);
    }
}
