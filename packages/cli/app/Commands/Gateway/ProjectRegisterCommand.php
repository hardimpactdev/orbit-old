<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\RunsOnGateway;
use App\Services\GatewayCliAdapter;
use HardImpact\Orbit\Core\Models\Gateway;
use HardImpact\Orbit\Core\Services\Gateway\GatewayManager;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

final class ProjectRegisterCommand extends Command
{
    use RunsOnGateway;

    protected $signature = 'project:register';

    protected $description = 'Register a project on the gateway for cross-node deployment tracking';

    public function handle(): int
    {
        /** @var GatewayManager $gatewayManager */
        $gatewayManager = app(GatewayManager::class);
        /** @var GatewayCliAdapter $adapter */
        $adapter = app(GatewayCliAdapter::class);

        if (! $gatewayManager->hasAny()) {
            $this->error('No gateways configured. Add one with: orbit gateway:add');

            return self::FAILURE;
        }

        $active = $adapter->detectActive();

        if ($active === null) {
            $this->error('No active WireGuard connection found.');

            return self::FAILURE;
        }

        $gatewayData = $active['gateway'];
        $gateway = Gateway::find($gatewayData['id']);

        if ($gateway === null) {
            $this->error('Gateway not found in database.');

            return self::FAILURE;
        }

        $this->info('Register Gateway Project');
        $this->line("  Gateway: {$gateway->name}");
        $this->newLine();

        $name = $this->ask('Project name');
        if (! $name) {
            $this->error('Project name is required.');

            return self::FAILURE;
        }

        $slug = Str::slug($name);
        $slug = $this->ask('Project slug', $slug);

        $repo = $this->ask('GitHub repository (e.g. org/repo, optional)');
        $domain = $this->ask('Production domain (e.g. srpm.nl, optional)');

        $this->line('Registering project on gateway...');

        $args = ['project:store', escapeshellarg($name), escapeshellarg($slug)];
        if ($repo) {
            $args[] = '--repo=' . escapeshellarg($repo);
        }
        if ($domain) {
            $args[] = '--domain=' . escapeshellarg($domain);
        }
        $args[] = '--json';

        $output = $this->runOnGateway($gateway, implode(' ', $args));

        if ($output === null) {
            $this->error('Failed to register project on gateway.');

            return self::FAILURE;
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded) || ! ($decoded['success'] ?? false)) {
            $error = $decoded['error'] ?? 'Unknown error';
            $this->error("Registration failed: {$error}");

            return self::FAILURE;
        }

        $project = $decoded['data'] ?? [];
        $this->newLine();
        $this->info("Project '{$project['name']}' registered.");
        $this->line("  Slug: {$project['slug']}");

        if ($project['cloudflare_zone_name'] ?? null) {
            $this->line("  Cloudflare zone: {$project['cloudflare_zone_name']}");
        }

        if ($project['production_domain'] ?? null) {
            $this->line("  Production domain: {$project['production_domain']}");
        }

        return self::SUCCESS;
    }
}
