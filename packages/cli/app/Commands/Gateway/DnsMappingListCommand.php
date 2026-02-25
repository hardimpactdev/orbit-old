<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithHumanOutput;
use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class DnsMappingListCommand extends Command
{
    use WithHumanOutput;
    use WithJsonOutput;

    protected $signature = 'list:dns-mappings {--json}';

    protected $description = 'List DNS TLD mappings on the active gateway';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $result = $adapter->forwardJson('gateway:dns-list --json');

        if (! $result['success']) {
            return $this->failWithMessage($result['error']);
        }

        $mappings = $result['data']['mappings'] ?? [];

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess(['mappings' => $mappings]);
        }

        if ($mappings === []) {
            $this->line('  <fg=gray>No DNS mappings configured.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $tableData = array_map(fn (array $mapping) => [
            'tld' => '.'.$mapping['tld'],
            'ip' => $mapping['ip'],
        ], $mappings);

        $this->renderForHumans($tableData);

        return self::SUCCESS;
    }
}
