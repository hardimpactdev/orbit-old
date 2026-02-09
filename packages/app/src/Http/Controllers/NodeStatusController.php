<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\DoctorService;
use HardImpact\Orbit\Core\Services\HorizonService;
use HardImpact\Orbit\Core\Services\MacPhpFpmConfigService;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;

class NodeStatusController extends Controller
{
    public function __construct(
        protected StatusService $status,
        protected DoctorService $doctor,
        protected MacPhpFpmConfigService $macPhpFpm,
        protected HorizonService $horizon,
    ) {}

    /**
     * Get node status (services, PHP versions, etc.).
     */
    public function status(Node $node)
    {
        if ($node->isLocal()) {
            $this->macPhpFpm->ensureConfigured();
        }

        $result = $this->status->status($node);

        // Transform horizon service based on whether this is the dev or production instance
        if ($result['success'] && isset($result['data']['services']['horizon'])) {
            unset($result['data']['services']['horizon']);

            $serviceKey = $this->horizon->getServiceKey();
            $result['data']['services'][$serviceKey] = $this->horizon->getStatusInfo();
        }

        return response()->json($result);
    }

    /**
     * Get projects status.
     */
    public function projects(Node $node)
    {
        $result = $this->status->projects($node);

        return response()->json($result);
    }

    /**
     * Run health checks on the node (doctor).
     */
    public function runDoctor(Node $node)
    {
        $result = $this->doctor->runChecks($node);

        return response()->json($result);
    }

    /**
     * Run quick connectivity check on the node.
     */
    public function quickCheck(Node $node)
    {
        $result = $this->doctor->quickCheck($node);

        return response()->json($result);
    }

    /**
     * Attempt to fix a specific doctor issue.
     */
    public function fixIssue(Node $node, string $check)
    {
        $result = $this->doctor->fixIssue($node, $check);

        return response()->json($result);
    }
}
