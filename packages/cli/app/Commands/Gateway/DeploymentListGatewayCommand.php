<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Models\Deployment;
use LaravelZero\Framework\Commands\Command;

final class DeploymentListGatewayCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'deployment:list
        {--project= : Filter by project slug}
        {--node= : Filter by node ID}
        {--status= : Filter by deployment status}
        {--json}';

    protected $description = 'List deployments across nodes (runs on gateway)';

    protected $hidden = true;

    public function handle(): int
    {
        $query = Deployment::with('node');

        if ($project = $this->option('project')) {
            $query->where('project_slug', $project);
        }

        if ($nodeId = $this->option('node')) {
            $query->where('node_id', (int) $nodeId);
        }

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $deployments = $query->get();

        $data = $deployments->map(fn (Deployment $deployment) => [
            'id' => $deployment->id,
            'project_slug' => $deployment->project_slug,
            'domain' => $deployment->domain,
            'node' => $deployment->node->name,
            'environment' => $deployment->node->environment->value,
            'status' => $deployment->status->value,
            'created_at' => $deployment->created_at->toDateTimeString(),
        ])->all();

        return $this->outputJsonSuccess(['deployments' => $data]);
    }
}
