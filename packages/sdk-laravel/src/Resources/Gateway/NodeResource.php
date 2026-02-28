<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources\Gateway;

use HardImpact\Orbit\Data\Gateway\Node;
use HardImpact\Orbit\Requests\Gateway\Nodes\ListNodes;
use HardImpact\Orbit\Requests\Gateway\Nodes\SyncNode;
use HardImpact\Orbit\Requests\Gateway\Nodes\UpdateCaddy;
use HardImpact\Orbit\Requests\Gateway\Nodes\UpdateNode;
use HardImpact\Orbit\Requests\Gateway\Nodes\UpdateTld;

class NodeResource extends GatewayResource
{
    /**
     * @return Node[]
     */
    public function list(?string $environment = null): array
    {
        $response = $this->connector->send(new ListNodes($environment));
        $data = $this->unwrap($response);

        return array_map(
            fn (array $node) => Node::from([
                'id' => $node['id'],
                'name' => $node['name'],
                'host' => $node['host'],
                'externalHost' => $node['external_host'] ?? null,
                'environment' => $node['environment'],
                'nodeType' => $node['node_type'],
                'status' => $node['status'],
                'isActive' => $node['is_active'],
                'tld' => $node['tld'] ?? null,
                'deploymentsCount' => $node['deployments_count'] ?? 0,
            ]),
            $data['nodes'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function update(
        int $nodeId,
        ?string $name = null,
        ?string $host = null,
        ?string $externalHost = null,
        ?string $user = null,
        ?int $port = null,
        ?string $environment = null,
        ?string $status = null,
        ?bool $isActive = null,
    ): array {
        $response = $this->connector->send(new UpdateNode(
            $nodeId,
            $name,
            $host,
            $externalHost,
            $user,
            $port,
            $environment,
            $status,
            $isActive,
        ));

        return $this->unwrap($response);
    }

    /** @return array<string, mixed> */
    public function updateTld(int $nodeId, string $newTld): array
    {
        $response = $this->connector->send(new UpdateTld($nodeId, $newTld));

        return $this->unwrap($response);
    }

    /** @return array<string, mixed> */
    public function updateCaddy(int $nodeId, ?string $slug = null, bool $dryRun = true): array
    {
        $response = $this->connector->send(new UpdateCaddy($nodeId, $slug, $dryRun));

        return $this->unwrap($response);
    }

    /** @return array<string, mixed> */
    public function sync(int $nodeId): array
    {
        $response = $this->connector->send(new SyncNode($nodeId));

        return $this->unwrap($response);
    }
}
