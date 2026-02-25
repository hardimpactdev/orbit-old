<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithHumanOutput;
use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class GatewayProjectListCommand extends Command
{
    use WithHumanOutput;
    use WithJsonOutput;

    protected $signature = 'list:gateway-projects {--json}';

    protected $description = 'List registered projects on the active gateway';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $result = $adapter->forwardJson('gateway:project-list --json');

        if (! $result['success']) {
            return $this->failWithMessage($result['error']);
        }

        $projects = $result['data']['projects'] ?? [];

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess(['projects' => $projects]);
        }

        if ($projects === []) {
            $this->line('  <fg=gray>No projects registered.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $tableData = array_map(fn (array $project) => [
            'slug' => $project['slug'],
            'name' => $project['name'],
            'domain' => $project['production_domain'],
            'zone' => $project['cloudflare_zone_name'],
            'deployments' => $project['deployment_count'],
        ], $projects);

        $this->renderForHumans($tableData);

        return self::SUCCESS;
    }
}
