<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Services\CloudflareService;
use LaravelZero\Framework\Commands\Command;

final class CloudflareSslCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'cloudflare:ssl-set {zone-id} {mode}
        {--json}';

    protected $description = 'Set Cloudflare zone SSL/TLS mode (runs on gateway)';

    protected $hidden = true;

    private const VALID_MODES = ['off', 'flexible', 'full', 'strict'];

    public function handle(CloudflareService $cloudflare): int
    {
        $mode = $this->argument('mode');

        if (! in_array($mode, self::VALID_MODES, true)) {
            return $this->outputJsonError(
                'Invalid SSL mode: '.$mode.'. Must be one of: '.implode(', ', self::VALID_MODES)
            );
        }

        if (! $cloudflare->isConfigured($this->argument('zone-id'))) {
            return $this->outputJsonError('Cloudflare is not configured. Set API token first.');
        }

        $success = $cloudflare->setSslMode($this->argument('zone-id'), $mode);

        if (! $success) {
            return $this->outputJsonError('Failed to set SSL mode.');
        }

        return $this->outputJsonSuccess([
            'zone_id' => $this->argument('zone-id'),
            'mode' => $mode,
        ]);
    }
}
