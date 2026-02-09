<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Controllers;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\ServiceControlService;
use HardImpact\Orbit\Core\Services\SshService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DnsController extends Controller
{
    public function __construct(
        protected SshService $ssh,
        protected ConfigurationService $config,
        protected ServiceControlService $serviceControl,
    ) {}

    /**
     * Get DNS mappings for a node.
     */
    public function index(Node $node)
    {
        $result = $this->config->getDnsMappings($node);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to get DNS mappings',
            ]);
        }

        return response()->json([
            'success' => true,
            'mappings' => $result['mappings'] ?? [],
        ]);
    }

    /**
     * Update DNS mappings for a node.
     */
    public function update(Request $request, Node $node)
    {
        $validated = $request->validate([
            'mappings' => 'required|array',
            'mappings.*.type' => ['required', 'string', Rule::in(['address', 'server'])],
            'mappings.*.tld' => 'nullable|string|regex:/^[a-z0-9-]+$/i',
            'mappings.*.value' => 'required|string',
        ]);

        foreach ($validated['mappings'] as $index => $mapping) {
            if ($mapping['type'] === 'address') {
                if (empty($mapping['tld'])) {
                    return response()->json([
                        'success' => false,
                        'error' => "Mapping at index {$index}: 'address' type requires a TLD",
                    ], 422);
                }

                if (! $this->isValidIp($mapping['value'])) {
                    return response()->json([
                        'success' => false,
                        'error' => "Mapping at index {$index}: Invalid IP address '{$mapping['value']}'",
                    ], 422);
                }
            } elseif ($mapping['type'] === 'server') {
                if (! $this->isValidIp($mapping['value'])) {
                    return response()->json([
                        'success' => false,
                        'error' => "Mapping at index {$index}: Invalid DNS server IP '{$mapping['value']}'",
                    ], 422);
                }

                if (! empty($mapping['tld'])) {
                    $dnsReachable = $this->validateDnsServer($node, $mapping['value']);
                    if (! $dnsReachable) {
                        return response()->json([
                            'success' => false,
                            'error' => "Mapping at index {$index}: DNS server '{$mapping['value']}' is not reachable",
                        ], 422);
                    }
                }
            }
        }

        $result = $this->config->setDnsMappings($node, $validated['mappings']);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to update DNS mappings',
            ]);
        }

        $restartResult = $this->serviceControl->restart($node, 'dns');

        return response()->json([
            'success' => true,
            'mappings' => $validated['mappings'],
            'dns_restarted' => $restartResult['success'] ?? false,
        ]);
    }

    /**
     * Validate if a string is a valid IP address.
     */
    protected function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate if a DNS server is reachable.
     */
    protected function validateDnsServer(Node $node, string $ip): bool
    {
        $command = "timeout 2 ping -c 1 -W 1 {$ip} > /dev/null 2>&1 && echo 'reachable' || echo 'unreachable'";
        $result = $this->ssh->execute($node, $command, 5);

        if (! $result['success']) {
            return false;
        }

        return trim($result['output']) === 'reachable';
    }
}
