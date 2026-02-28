<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources;

use HardImpact\Orbit\Data\Worktree;
use HardImpact\Orbit\Requests\Worktrees\ListWorktrees;
use Saloon\Http\BaseResource;

class WorktreeResource extends BaseResource
{
    /**
     * @return Worktree[]
     */
    public function list(): array
    {
        $data = $this->connector->send(new ListWorktrees)->json();

        return array_map(Worktree::from(...), $data['worktrees'] ?? []);
    }
}
