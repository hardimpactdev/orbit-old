<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Enums\NodeStatus;
use HardImpact\Orbit\Core\Enums\NodeType;
use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Support\Collection;

class NodeService
{
    public function __construct(
        private SshService $sshService
    ) {}

    public function addNode(string $host, string $user, int $port, NodeType $type, ?string $name = null): Node
    {
        return Node::create([
            'name' => $name ?? $this->generateNodeName($type, $host),
            'host' => $host,
            'user' => $user,
            'port' => $port,
            'node_type' => $type,
            'status' => NodeStatus::Active,
        ]);
    }

    public function testConnection(Node $node): bool
    {
        if ($node->isLocal()) {
            return true;
        }

        return $this->sshService->testConnection(
            $node->host,
            $node->user,
            $node->port
        );
    }

    public function getByType(NodeType $type): Collection
    {
        return Node::where('node_type', $type->value)->get();
    }

    public function setActive(Node $node): void
    {
        Node::query()->update(['is_active' => false]);
        $node->update(['is_active' => true]);
    }

    private function generateNodeName(NodeType $type, string $host): string
    {
        $count = Node::where('node_type', $type->value)->count();
        $typeLabel = ucfirst($type->value);

        return $count > 0 ? "{$typeLabel} " . ($count + 1) : $typeLabel;
    }
}
