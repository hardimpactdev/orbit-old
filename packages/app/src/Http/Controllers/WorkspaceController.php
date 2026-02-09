<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\OrbitCli\WorkspaceService;
use HardImpact\Orbit\App\Http\Controllers\Concerns\ProvidesRemoteApiUrl;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    use ProvidesRemoteApiUrl;

    public function __construct(
        protected WorkspaceService $workspace,
    ) {}

    /**
     * List all workspaces for a node (Inertia page).
     */
    public function index(Node $node): \Inertia\Response
    {
        $editor = $node->getEditor();
        $remoteApiUrl = $this->getRemoteApiUrl($node);

        return \Inertia\Inertia::render('nodes/Workspaces', [
            'node' => $node,
            'editor' => $editor,
            'remoteApiUrl' => $remoteApiUrl,
        ]);
    }

    /**
     * API endpoint for workspaces list.
     */
    public function indexApi(Node $node)
    {
        $result = $this->workspace->workspacesList($node);

        if ($result['success'] && isset($result['data']['workspaces'])) {
            $result['data']['workspaces'] = array_map(
                fn ($workspace) => $this->normalizeWorkspaceData($workspace),
                $result['data']['workspaces']
            );
        }

        return response()->json($result);
    }

    /**
     * Show the create workspace form.
     */
    public function create(Node $node): \Inertia\Response
    {
        return \Inertia\Inertia::render('nodes/workspaces/Create', [
            'node' => $node,
        ]);
    }

    /**
     * Store a new workspace.
     */
    public function store(Request $request, Node $node)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|regex:/^[a-z0-9-]+$/',
        ]);

        $result = $this->workspace->workspaceCreate($node, $validated['name']);

        if (! $result['success']) {
            return back()->with('error', $result['error'] ?? 'Failed to create workspace');
        }

        return redirect()->route('nodes.workspaces', $node)
            ->with('success', "Workspace '{$validated['name']}' created successfully");
    }

    /**
     * Show a single workspace.
     */
    public function show(Node $node, string $workspace): \Inertia\Response
    {
        $editor = $node->getEditor();
        $remoteApiUrl = $this->getRemoteApiUrl($node);

        return \Inertia\Inertia::render('nodes/workspaces/Show', [
            'node' => $node,
            'workspaceName' => $workspace,
            'editor' => $editor,
            'remoteApiUrl' => $remoteApiUrl,
        ]);
    }

    /**
     * API endpoint for single workspace.
     */
    public function showApi(Node $node, string $workspace)
    {
        $result = $this->workspace->workspacesList($node);
        $workspaces = $result['success'] ? ($result['data']['workspaces'] ?? []) : [];

        $workspaceData = collect($workspaces)->firstWhere('name', $workspace);

        if (! $workspaceData) {
            return response()->json([
                'success' => false,
                'error' => 'Workspace not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->normalizeWorkspaceData($workspaceData),
        ]);
    }

    /**
     * Delete a workspace.
     */
    public function destroy(Node $node, string $workspace)
    {
        $result = $this->workspace->workspaceDelete($node, $workspace);

        if (! $result['success']) {
            return back()->with('error', $result['error'] ?? 'Failed to delete workspace');
        }

        return redirect()->route('nodes.workspaces', $node)
            ->with('success', "Workspace '{$workspace}' deleted successfully");
    }

    /**
     * Add a project to a workspace.
     */
    public function addProject(Request $request, Node $node, string $workspace)
    {
        $validated = $request->validate([
            'project' => 'required|string|max:255',
        ]);

        $result = $this->workspace->workspaceAddProject($node, $workspace, $validated['project']);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to add project to workspace',
            ]);
        }

        return response()->json([
            'success' => true,
            'workspace' => $result['data']['workspace'] ?? null,
        ]);
    }

    /**
     * Remove a project from a workspace.
     */
    public function removeProject(Node $node, string $workspace, string $project)
    {
        $result = $this->workspace->workspaceRemoveProject($node, $workspace, $project);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to remove project from workspace',
            ]);
        }

        return response()->json([
            'success' => true,
            'workspace' => $result['data']['workspace'] ?? null,
        ]);
    }

    /**
     * Normalize workspace data from CLI for frontend consistency.
     */
    protected function normalizeWorkspaceData(array $workspace): array
    {
        return $workspace;
    }
}
