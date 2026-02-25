<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithHumanOutput;
use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class DeploymentListCommand extends Command
{
    use WithHumanOutput;
    use WithJsonOutput;

    protected $signature = 'list:deployments
        {--project= : Filter by project slug}
        {--node= : Filter by node ID}
        {--status= : Filter by deployment status}
        {--json}';

    protected $description = 'List deployments across nodes';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $args = ['deployment:list'];

        if ($project = $this->option('project')) {
            $args[] = '--project='.$project;
        }

        if ($node = $this->option('node')) {
            $args[] = '--node='.$node;
        }

        if ($status = $this->option('status')) {
            $args[] = '--status='.$status;
        }

        $args[] = '--json';

        $result = $adapter->forwardJson(implode(' ', $args));

        if (! $result['success']) {
            return $this->failWithMessage($result['error']);
        }

        $deployments = $result['data']['deployments'] ?? [];

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess(['deployments' => $deployments]);
        }

        if ($deployments === []) {
            $this->line('  <fg=gray>No deployments found.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $tableData = array_map(fn (array $deployment) => [
            'id' => $deployment['id'],
            'project' => $deployment['project_slug'],
            'domain' => $deployment['domain'],
            'node' => $deployment['node'],
            'environment' => $deployment['environment'],
            'status' => $deployment['status'],
        ], $deployments);

        $this->renderForHumans($tableData);

        return self::SUCCESS;
    }
}
