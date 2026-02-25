<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithHumanOutput;
use App\Concerns\WithJsonOutput;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use LaravelZero\Framework\Commands\Command;

final class CloudflareAddRecordCommand extends Command
{
    use WithHumanOutput;
    use WithJsonOutput;

    protected $signature = 'cloudflare:add-record {name} {content}
        {--zone-id= : Cloudflare zone ID}
        {--type=A : Record type (A, CNAME, TXT, etc.)}
        {--proxied : Proxy through Cloudflare}
        {--json}';

    protected $description = 'Add a DNS record to Cloudflare';

    public function handle(GatewayManager $gatewayManager, GatewayCliAdapter $adapter): int
    {
        if (! $gatewayManager->hasAny()) {
            return $this->failWithMessage('No gateways configured. Add one with: orbit gateway:add');
        }

        $args = [
            'cloudflare:record-add',
            $this->argument('name'),
            $this->argument('content'),
        ];

        if ($zoneId = $this->option('zone-id')) {
            $args[] = '--zone-id='.$zoneId;
        }

        $args[] = '--type='.$this->option('type');

        if ($this->option('proxied')) {
            $args[] = '--proxied';
        }

        $args[] = '--json';

        $result = $adapter->forwardJson(implode(' ', $args));

        if (! $result['success']) {
            return $this->failWithMessage($result['error']);
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess($result['data']);
        }

        $this->info('DNS record created.');
        $this->renderForHumans($result['data']);

        return self::SUCCESS;
    }
}
