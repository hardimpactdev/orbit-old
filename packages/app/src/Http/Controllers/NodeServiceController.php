<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\OrbitCli\ServiceControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles service control operations for nodes.
 *
 * This includes starting, stopping, restarting services,
 * viewing logs, and managing service configuration.
 */
class NodeServiceController extends Controller
{
    public function __construct(
        protected ServiceControlService $serviceControl,
    ) {}

    /**
     * Start all services for a node.
     */
    public function start(Request $request, Node $node): JsonResponse
    {
        $project = $request->input('project');
        $result = $this->serviceControl->start($node, $project);

        return response()->json($result);
    }

    /**
     * Stop all services for a node.
     */
    public function stop(Request $request, Node $node): JsonResponse
    {
        $project = $request->input('project');
        $result = $this->serviceControl->stop($node, $project);

        return response()->json($result);
    }

    /**
     * Restart all services for a node.
     */
    public function restart(Request $request, Node $node): JsonResponse
    {
        $project = $request->input('project');
        $result = $this->serviceControl->restart($node, $project);

        return response()->json($result);
    }

    /**
     * Start a single Docker service.
     */
    public function startService(Node $node, string $service): JsonResponse
    {
        $result = $this->serviceControl->startService($node, $service);

        return response()->json($result);
    }

    /**
     * Start a single host service.
     */
    public function startHostService(Node $node, string $service): JsonResponse
    {
        $result = $this->serviceControl->startHostService($node, $service);

        return response()->json($result);
    }

    /**
     * Stop a single Docker service.
     */
    public function stopService(Node $node, string $service): JsonResponse
    {
        $result = $this->serviceControl->stopService($node, $service);

        return response()->json($result);
    }

    /**
     * Stop a single host service.
     */
    public function stopHostService(Node $node, string $service): JsonResponse
    {
        $result = $this->serviceControl->stopHostService($node, $service);

        return response()->json($result);
    }

    /**
     * Restart a single Docker service.
     */
    public function restartService(Node $node, string $service): JsonResponse
    {
        $result = $this->serviceControl->restartService($node, $service);

        return response()->json($result);
    }

    /**
     * Restart a single host service.
     */
    public function restartHostService(Node $node, string $service): JsonResponse
    {
        $result = $this->serviceControl->restartHostService($node, $service);

        return response()->json($result);
    }

    /**
     * Get logs for a single Docker service.
     */
    public function serviceLogs(Request $request, Node $node, string $service): JsonResponse
    {
        $since = $request->query('since');
        $result = $this->serviceControl->serviceLogs($node, $service, 200, $since);

        return response()->json($result);
    }

    /**
     * Get logs for a host service (Caddy, PHP-FPM, Horizon).
     */
    public function hostServiceLogs(Request $request, Node $node, string $service): JsonResponse
    {
        $since = $request->query('since');
        $result = $this->serviceControl->hostServiceLogs($node, $service, 200, $since);

        return response()->json($result);
    }

    /**
     * Show available services.
     */
    public function availableServices(Node $node): JsonResponse
    {
        $result = $this->serviceControl->available($node);

        return response()->json($result);
    }

    /**
     * Enable a service.
     */
    public function enableService(Request $request, Node $node, string $service): JsonResponse
    {
        $options = $request->input('options', []);
        $result = $this->serviceControl->enable($node, $service, $options);

        return response()->json($result);
    }

    /**
     * Disable a service.
     */
    public function disableService(Node $node, string $service): JsonResponse
    {
        $result = $this->serviceControl->disable($node, $service);

        return response()->json($result);
    }

    /**
     * Update service config.
     */
    public function configureService(Request $request, Node $node, string $service): JsonResponse
    {
        $config = $request->input('config', []);
        $result = $this->serviceControl->configure($node, $service, $config);

        return response()->json($result);
    }

    /**
     * Get service details.
     */
    public function serviceInfo(Node $node, string $service): JsonResponse
    {
        $result = $this->serviceControl->info($node, $service);

        return response()->json($result);
    }
}
