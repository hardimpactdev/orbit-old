<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\App\Http\Controllers\Concerns\ProvidesRemoteApiUrl;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Models\SshKey;
use HardImpact\Orbit\Core\Services\DnsResolverService;
use HardImpact\Orbit\Core\Services\MacPhpFpmConfigService;
use HardImpact\Orbit\Core\Services\NodeManager;
use HardImpact\Orbit\Core\Services\NotificationService;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controller for node CRUD operations and core node features.
 *
 * Most node-related functionality has been extracted to specialized controllers:
 * - NodeStatusController: Health checks, doctor
 * - NodeConfigController: Config save/get, TLD/DNS
 * - NodeProjectController: Project CRUD, GitHub integration
 * - NodeServiceController: Service start/stop/restart
 * - PhpConfigController: PHP version management
 * - WorkspaceController: Workspace management
 * - WorktreeController: Git worktree management
 * - PackageController: Composer package linking
 */
class NodeController extends Controller
{
    use ProvidesRemoteApiUrl;

    public function __construct(
        protected StatusService $status,
        protected ConfigurationService $config,
        protected DnsResolverService $dnsResolver,
        protected MacPhpFpmConfigService $macPhpFpm,
        protected NodeManager $nodes,
        protected NotificationService $notificationService,
    ) {}

    public function index(): \Inertia\Response|\Illuminate\Http\RedirectResponse
    {
        $nodes = Node::all();
        $hasLocalNode = Node::where('is_default', true)->exists();

        // Desktop mode: auto-create local node if none exist
        if ($nodes->isEmpty() && config('orbit.mode') === 'desktop') {
            $localNode = Node::create([
                'name' => 'Local',
                'host' => 'localhost',
                'user' => get_current_user(),
                'port' => 22,
                'is_default' => true,
            ]);

            return redirect()->route('nodes.show', $localNode)
                ->with('success', 'Local node created automatically.');
        }

        // If only one node exists, redirect directly to it
        if ($nodes->count() === 1) {
            return redirect()->route('nodes.show', $nodes->first());
        }

        return \Inertia\Inertia::render('nodes/Index', [
            'nodes' => $nodes,
            'hasLocalNode' => $hasLocalNode,
        ]);
    }

    public function create(): \Inertia\Response
    {
        $sshKeys = \HardImpact\Orbit\Core\Models\SshKey::orderBy('is_default', 'desc')->orderBy('name')->get();
        $availableSshKeys = SshKey::getAvailableLocalKeys();
        $hasLocalNode = Node::where('is_default', true)->exists();

        return \Inertia\Inertia::render('nodes/Create', [
            'currentUser' => get_current_user(),
            'sshKeys' => $sshKeys,
            'availableSshKeys' => $availableSshKeys,
            'hasLocalNode' => $hasLocalNode,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
        ]);

        $node = Node::create($validated);

        return redirect()->route('nodes.show', $node)
            ->with('success', "Node '{$node->name}' added successfully.");
    }

    public function show(Node $node): \Inertia\Response
    {
        // If node is being provisioned or has error, show provisioning view
        if ($node->isProvisioning() || $node->hasError()) {
            $sshPublicKey = Setting::getSshPublicKey();

            return \Inertia\Inertia::render('nodes/Provisioning', [
                'node' => $node,
                'sshPublicKey' => $sshPublicKey,
            ]);
        }

        // Only check installation synchronously (fast), load status/projects via AJAX
        $installation = $this->status->checkInstallation($node);
        $editor = $node->getEditor();
        $remoteApiUrl = $this->getRemoteApiUrl($node);
        $reverb = $this->config->getReverbConfig($node);

        return \Inertia\Inertia::render('nodes/Show', [
            'node' => $node,
            'installation' => $installation,
            'editor' => $editor,
            'remoteApiUrl' => $remoteApiUrl,
            'reverb' => $reverb['success'] ? [
                'enabled' => $reverb['enabled'] ?? false,
                'host' => $reverb['host'] ?? null,
                'port' => $reverb['port'] ?? null,
                'scheme' => $reverb['scheme'] ?? null,
                'app_key' => $reverb['app_key'] ?? null,
            ] : [
                'enabled' => false,
            ],
        ]);
    }

    public function edit(Node $node): \Inertia\Response
    {
        return \Inertia\Inertia::render('nodes/Edit', [
            'node' => $node,
        ]);
    }

    public function update(Request $request, Node $node)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
        ]);

        $node->update($validated);

        return redirect()->route('nodes.index')
            ->with('success', "Node '{$node->name}' updated successfully.");
    }

    public function destroy(Node $node)
    {
        $name = $node->name;

        // Get the node's TLD before deletion for cleanup
        $tld = null;
        try {
            $config = $this->config->getConfig($node);
            $tld = $config['success'] ? ($config['data']['tld'] ?? null) : null;
        } catch (\Exception $e) {
            // Ignore config fetch errors - node might be unreachable
        }

        // Delete the node
        $node->delete();

        // Clean up DNS resolver if no other nodes use this TLD
        if ($tld) {
            try {
                $otherNodesWithTld = Node::where('tld', $tld)->count();
                if ($otherNodesWithTld === 0) {
                    $this->dnsResolver->removeResolver($tld);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("DNS resolver cleanup failed: {$e->getMessage()}");
            }
        }

        return redirect()->route('nodes.index')
            ->with('success', "Node '{$name}' removed successfully.");
    }

    public function setDefault(Node $node): \Illuminate\Http\RedirectResponse
    {
        DB::transaction(function () use ($node): void {
            Node::where('id', '!=', $node->id)->where('is_default', true)->update(['is_default' => false]);
            $node->update(['is_default' => true]);
        });

        return redirect()->route('nodes.show', $node);
    }

    public function switchNode(Node $node, Request $request)
    {
        $activeNode = $this->nodes->setActive($node->id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'node' => $activeNode,
            ]);
        }

        return redirect()->route('nodes.show', $activeNode)
            ->with('success', "Node '{$activeNode->name}' is now active.");
    }

    public function testConnection(Node $node)
    {
        // For local nodes, always succeed
        if ($node->isLocal()) {
            $node->update(['last_connected_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Local connection',
            ]);
        }

        // For remote nodes, use API instead of SSH (much faster)
        $result = $this->status->status($node);

        if ($result['success']) {
            $node->update(['last_connected_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Connected successfully',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['error'] ?? 'Connection failed',
        ]);
    }

    /**
     * Services page (Inertia view).
     */
    public function servicesPage(Node $node): \Inertia\Response
    {
        $remoteApiUrl = $this->getRemoteApiUrl($node);
        $editor = $node->getEditor();

        $reverb = $this->config->getReverbConfig($node);

        return \Inertia\Inertia::render('nodes/Services', [
            'node' => $node,
            'remoteApiUrl' => $remoteApiUrl,
            'editor' => $editor,
            'localPhpIniPath' => $node->isLocal() ? $this->macPhpFpm->getGlobalIniPath() : null,
            'homebrewPrefix' => $node->isLocal() ? $this->macPhpFpm->getHomebrewPrefix() : null,
            'reverb' => $reverb['success'] ? [
                'enabled' => $reverb['enabled'] ?? false,
                'host' => $reverb['host'] ?? null,
                'port' => $reverb['port'] ?? null,
                'scheme' => $reverb['scheme'] ?? null,
                'app_key' => $reverb['app_key'] ?? null,
            ] : [
                'enabled' => false,
            ],
        ]);
    }

    /**
     * Node settings page.
     */
    public function settings(Node $node): \Inertia\Response
    {
        $remoteApiUrl = $this->getRemoteApiUrl($node);

        $sshKeys = \HardImpact\Orbit\Core\Models\SshKey::orderBy('is_default', 'desc')->orderBy('name')->get();
        $availableSshKeys = \HardImpact\Orbit\Core\Models\SshKey::getAvailableLocalKeys();
        $templateFavorites = \HardImpact\Orbit\Core\Models\TemplateFavorite::orderByDesc('usage_count')->get();
        $notificationsEnabled = $this->notificationService->isEnabled();
        $menuBarEnabled = \HardImpact\Orbit\Core\Models\UserPreference::getValue('menu_bar_enabled', false);

        return \Inertia\Inertia::render('nodes/Configuration', [
            'node' => $node,
            'remoteApiUrl' => $remoteApiUrl,
            'editor' => $node->getEditor(),
            'editorOptions' => Setting::getEditorOptions(),
            'sshKeys' => $sshKeys,
            'availableSshKeys' => $availableSshKeys,
            'templateFavorites' => $templateFavorites,
            'notificationsEnabled' => $notificationsEnabled,
            'menuBarEnabled' => $menuBarEnabled,
        ]);
    }

    /**
     * Update node settings.
     */
    public function updateSettings(Request $request, Node $node)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'editor_scheme' => 'nullable|string|in:'.implode(',', array_keys(Setting::getEditorOptions())),
        ]);

        $node->update($validated);

        return redirect()->back()->with('success', 'Node settings updated.');
    }

    /**
     * Update external access settings.
     */
    public function updateExternalAccess(Request $request, Node $node)
    {
        $validated = $request->validate([
            'external_access' => 'required|boolean',
            'external_host' => 'nullable|string|max:255',
        ]);

        $node->update([
            'external_access' => $validated['external_access'],
            'external_host' => $validated['external_host'] ?: null,
        ]);

        return redirect()->back()->with('success', 'External access settings updated.');
    }

    /**
     * Get instance info for the local node.
     * Used by desktop app to fetch the canonical display name.
     */
    public function instanceInfo()
    {
        $localNode = Node::getSelf();

        if (! $localNode) {
            return response()->json([
                'success' => false,
                'error' => 'No local node configured',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $localNode->name,
                'tld' => $localNode->tld,
                'cli_version' => $localNode->cli_version,
            ],
        ]);
    }

    /**
     * Update instance info for the local node.
     * Used by desktop app to rename the node remotely.
     */
    public function updateInstanceInfo(Request $request)
    {
        $localNode = Node::getSelf();

        if (! $localNode) {
            return response()->json([
                'success' => false,
                'error' => 'No local node configured',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $localNode->update(['name' => $validated['name']]);

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $localNode->name,
                'tld' => $localNode->tld,
                'cli_version' => $localNode->cli_version,
            ],
        ]);
    }
}
