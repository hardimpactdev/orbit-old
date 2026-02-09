<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Enums\NodeStatus;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Models\SshKey;
use HardImpact\Orbit\Core\Services\CliUpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

class ProvisioningController extends Controller
{
    public function __construct(
        protected CliUpdateService $cliUpdateService,
    ) {}

    public function create(): \Inertia\Response
    {
        $sshKeys = SshKey::orderBy('is_default', 'desc')->orderBy('name')->get();
        $availableSshKeys = SshKey::getAvailableLocalKeys();

        return \Inertia\Inertia::render('provisioning/Create', [
            'sshKeys' => $sshKeys,
            'availableSshKeys' => $availableSshKeys,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
            'ssh_public_key' => 'required|string',
        ]);

        // Store the SSH key if it changed
        if ($validated['ssh_public_key'] !== Setting::getSshPublicKey()) {
            Setting::setSshPublicKey($validated['ssh_public_key']);
        }

        // Create the node record immediately with provisioning status
        $node = Node::create([
            'name' => $validated['name'],
            'host' => $validated['host'],
            'user' => $validated['user'],
            'port' => config('orbit-ui.ports.ssh', 22),
            'status' => NodeStatus::Provisioning,
        ]);

        // Redirect to the node show page immediately - provisioning runs in background
        return redirect()->route('nodes.show', $node);
    }

    public function run(Request $request, Node $node)
    {
        $validated = $request->validate([
            'ssh_public_key' => 'required|string',
        ]);

        // Handle local node provisioning
        if ($node->isLocal()) {
            return $this->runLocalProvisioning($node);
        }

        // Handle remote node provisioning
        // Clear old SSH host keys BEFORE starting provisioning
        Process::run(sprintf('ssh-keygen -R %s 2>/dev/null', escapeshellarg($node->host)));

        // Run provisioning in the background so the HTTP request returns immediately
        $sshKey = $validated['ssh_public_key'];
        $artisanPath = base_path('artisan');

        // Spawn the artisan command in the background
        $command = sprintf(
            'php %s environment:provision %d %s > /dev/null 2>&1 &',
            escapeshellarg($artisanPath),
            $node->id,
            escapeshellarg((string) $sshKey)
        );

        // Use popen for background execution
        pclose(popen($command, 'r'));

        return response()->json([
            'started' => true,
            'message' => 'Provisioning started in background',
        ]);
    }

    protected function runLocalProvisioning(Node $node): \Illuminate\Http\JsonResponse
    {
        $tld = $node->tld ?? 'test';
        $logPath = storage_path("logs/provision-{$node->id}.log");

        // Ensure the CLI is installed
        $cliUpdate = $this->cliUpdateService;
        if (! $cliUpdate->isInstalled()) {
            return response()->json([
                'success' => false,
                'error' => 'Orbit CLI not installed. Please install it first.',
            ], 400);
        }

        $pharPath = $cliUpdate->getPharPath();

        // Clear any existing log file
        if (file_exists($logPath)) {
            unlink($logPath);
        }

        // Spawn CLI setup command in background
        $command = sprintf(
            'nohup php %s setup --json --tld=%s > %s 2>&1 &',
            escapeshellarg($pharPath),
            escapeshellarg($tld),
            escapeshellarg($logPath)
        );

        // Use popen for background execution
        pclose(popen($command, 'r'));

        return response()->json([
            'started' => true,
            'message' => 'Local provisioning started in background',
        ]);
    }

    public function status(Node $node)
    {
        // For local nodes, parse progress from CLI log file
        if ($node->isLocal() && $node->status === NodeStatus::Provisioning) {
            $this->parseCliProgress($node);
            $node->refresh();
        }

        return response()->json([
            'status' => $node->status,
            'provisioning_step' => $node->provisioning_step,
            'provisioning_total_steps' => $node->provisioning_total_steps,
            'provisioning_log' => $node->provisioning_log,
            'provisioning_error' => $node->provisioning_error,
        ]);
    }

    protected function parseCliProgress(Node $node): void
    {
        $logPath = storage_path("logs/provision-{$node->id}.log");

        if (! file_exists($logPath)) {
            return;
        }

        $content = file_get_contents($logPath);
        $lines = explode("\n", trim($content));

        $log = [];
        $currentStep = 0;
        $totalSteps = config('orbit-ui.provisioning.mac_setup_steps', 15);
        $hasError = false;
        $errorMessage = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line === '0') {
                continue;
            }

            // Try to parse as JSON
            $decoded = json_decode($line, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                if (isset($decoded['type'])) {
                    if ($decoded['type'] === 'step' && isset($decoded['message'])) {
                        $log[] = ['step' => $decoded['message']];
                        $currentStep = $decoded['step'] ?? $currentStep + 1;
                        $totalSteps = $decoded['total'] ?? $totalSteps;
                    } elseif ($decoded['type'] === 'info' && isset($decoded['message'])) {
                        $log[] = ['info' => $decoded['message']];
                    } elseif ($decoded['type'] === 'error' && isset($decoded['message'])) {
                        $log[] = ['error' => $decoded['message']];
                        $hasError = true;
                        $errorMessage = $decoded['message'];
                    } elseif ($decoded['type'] === 'success' && isset($decoded['message'])) {
                        $node->update([
                            'status' => NodeStatus::Active,
                            'provisioning_step' => $totalSteps,
                            'provisioning_total_steps' => $totalSteps,
                            'provisioning_log' => $log,
                        ]);

                        return;
                    }
                }
            } elseif (stripos($line, 'error') !== false || stripos($line, 'failed') !== false) {
                $log[] = ['error' => $line];
                $hasError = true;
                $errorMessage ??= $line;
            } else {
                $log[] = ['info' => $line];
            }
        }

        $updateData = [
            'provisioning_step' => $currentStep,
            'provisioning_total_steps' => $totalSteps,
            'provisioning_log' => $log,
        ];

        if ($hasError) {
            $updateData['status'] = NodeStatus::Error;
            $updateData['provisioning_error'] = $errorMessage;
        }

        $node->update($updateData);
    }

    public function checkServer(Request $request)
    {
        $validated = $request->validate([
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
        ]);

        $result = \HardImpact\Orbit\Core\Services\ProvisioningService::checkExistingSetup(
            $validated['host'],
            $validated['user']
        );

        return response()->json($result);
    }
}
