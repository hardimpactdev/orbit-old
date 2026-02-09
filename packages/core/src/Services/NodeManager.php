<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Support\Facades\DB;

class NodeManager
{
    public function current(): ?Node
    {
        $node = Node::where('is_active', true)->first();

        if ($node) {
            return $node;
        }

        $fallback = Node::where('is_default', true)->first() ?? Node::first();

        if (! $fallback) {
            return null;
        }

        return $this->setActive($fallback->id);
    }

    public function setActive(int $nodeId): Node
    {
        $node = Node::findOrFail($nodeId);

        DB::transaction(function () use ($node) {
            Node::where('id', '!=', $node->id)->update(['is_active' => false]);
            $node->update(['is_active' => true]);
        });

        return $node->refresh();
    }
}
