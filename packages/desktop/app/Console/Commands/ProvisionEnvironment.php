<?php

declare(strict_types=1);

namespace App\Console\Commands;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\ProvisioningService;
use Illuminate\Console\Command;

class ProvisionEnvironment extends Command
{
    protected $signature = 'node:provision {node} {ssh_public_key}';

    protected $description = 'Provision a node with the Orbit stack';

    public function handle(ProvisioningService $provisioning): int
    {
        $node = Node::findOrFail($this->argument('node'));
        $sshPublicKey = $this->argument('ssh_public_key');

        $this->info("Starting provisioning for {$node->name} ({$node->host})...");

        $success = $provisioning->provision($node, $sshPublicKey);

        if ($success) {
            $this->info('Provisioning completed successfully!');

            return Command::SUCCESS;
        }
        $this->error('Provisioning failed: '.$node->fresh()->provisioning_error);

        return Command::FAILURE;
    }
}
