<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Concerns\WithJsonOutput;
use HardImpact\Orbit\Core\Enums\NodeEnvironment;
use HardImpact\Orbit\Core\Enums\NodeStatus;
use HardImpact\Orbit\Core\Models\Node;
use LaravelZero\Framework\Commands\Command;

final class NodeUpdateCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'node:update {node : Node ID or name}
        {--name= : New node name}
        {--host= : New host (IP or hostname)}
        {--external-host= : New external host (public IP for Cloudflare DNS)}
        {--environment= : Environment: development, staging, production}
        {--status= : Status: active, provisioning, error}
        {--user= : SSH user}
        {--port= : SSH port}
        {--is-active : Set as active node}
        {--json}';

    protected $description = 'Update a node\'s properties';

    public function handle(): int
    {
        $identifier = $this->argument('node');
        $node = is_numeric($identifier)
            ? Node::find((int) $identifier)
            : Node::where('name', $identifier)->first();

        if ($node === null) {
            return $this->failWithMessage("Node not found: {$identifier}");
        }

        $updates = [];

        if ($this->option('name') !== null) {
            $updates['name'] = $this->option('name');
        }

        if ($this->option('host') !== null) {
            $updates['host'] = $this->option('host');
        }

        if ($this->option('external-host') !== null) {
            $updates['external_host'] = $this->option('external-host');
        }

        if ($this->option('user') !== null) {
            $updates['user'] = $this->option('user');
        }

        if ($this->option('port') !== null) {
            $updates['port'] = (int) $this->option('port');
        }

        if ($this->option('is-active')) {
            $updates['is_active'] = true;
        }

        if ($environment = $this->option('environment')) {
            $valid = NodeEnvironment::tryFrom($environment);
            if ($valid === null) {
                $allowed = implode(', ', array_map(fn ($e) => $e->value, NodeEnvironment::cases()));

                return $this->failWithMessage("Invalid environment: {$environment}. Must be one of: {$allowed}");
            }
            $updates['environment'] = $valid;
        }

        if ($status = $this->option('status')) {
            $valid = NodeStatus::tryFrom($status);
            if ($valid === null) {
                $allowed = implode(', ', array_map(fn ($s) => $s->value, NodeStatus::cases()));

                return $this->failWithMessage("Invalid status: {$status}. Must be one of: {$allowed}");
            }
            $updates['status'] = $valid;
        }

        if ($updates === []) {
            return $this->failWithMessage('No updates specified. Use --name, --host, --environment, etc.');
        }

        try {
            $node->update($updates);
        } catch (\InvalidArgumentException $e) {
            return $this->failWithMessage($e->getMessage());
        }

        $node->refresh();

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'id' => $node->id,
                'name' => $node->name,
                'host' => $node->host,
                'external_host' => $node->external_host,
                'environment' => $node->environment->value,
                'status' => $node->status->value,
                'user' => $node->user,
                'port' => $node->port,
                'is_active' => $node->is_active,
            ]);
        }

        $this->info("Node '{$node->name}' updated.");

        foreach ($updates as $key => $value) {
            $display = $value instanceof \BackedEnum ? $value->value : $value;
            $this->line("  <fg=gray>{$key}:</> {$display}");
        }

        return self::SUCCESS;
    }
}
