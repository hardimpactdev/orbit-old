<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithHumanOutput;
use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class CloudflareDnsCommand extends Command
{
    use WithHumanOutput;
    use WithJsonOutput;

    protected $signature = 'cloudflare:dns
        {--zone-id= : Cloudflare zone ID}
        {--name= : Filter by record name (FQDN)}
        {--type= : Filter by record type (A, CNAME, TXT, etc.)}
        {--json}';

    protected $description = 'List Cloudflare DNS records';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $args = ['cloudflare:dns-list'];

        if ($zoneId = $this->option('zone-id')) {
            $args[] = '--zone-id='.$zoneId;
        }

        if ($name = $this->option('name')) {
            $args[] = '--name='.$name;
        }

        if ($type = $this->option('type')) {
            $args[] = '--type='.$type;
        }

        $args[] = '--json';

        $result = $adapter->forwardJson(implode(' ', $args));

        if (! $result['success']) {
            return $this->failWithMessage($result['error']);
        }

        $records = $result['data']['records'] ?? [];

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess(['records' => $records]);
        }

        if ($records === []) {
            $this->line('  <fg=gray>No DNS records found.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $tableData = array_map(fn (array $record) => [
            'type' => $record['type'],
            'name' => $record['name'],
            'content' => $record['content'],
            'proxied' => $record['proxied'],
            'id' => $record['id'],
        ], $records);

        $this->renderForHumans($tableData);

        return self::SUCCESS;
    }
}
