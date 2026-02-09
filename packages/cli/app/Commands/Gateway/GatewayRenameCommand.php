<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Models\Gateway;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class GatewayRenameCommand extends Command
{
    protected $signature = 'gateway:rename {id? : Gateway ID} {name? : New name}';

    protected $description = 'Rename a gateway server';

    public function handle(): int
    {
        $id = $this->argument('id');

        if ($id === null) {
            $gateways = Gateway::all();

            if ($gateways->isEmpty()) {
                $this->warn('No gateways configured.');

                return self::SUCCESS;
            }

            $id = select(
                label: 'Select gateway to rename',
                options: $gateways->mapWithKeys(
                    fn (Gateway $g) => [$g->id => "{$g->name} ({$g->ip_address})"]
                )->all(),
            );
        }

        $gateway = Gateway::find($id);

        if ($gateway === null) {
            $this->error("Gateway not found: {$id}");

            return self::FAILURE;
        }

        $name = $this->argument('name');

        if ($name === null) {
            $name = text(
                label: 'New name',
                placeholder: $gateway->name,
                required: true,
            );
        }

        $old = $gateway->name;
        $gateway->update(['name' => $name]);

        $this->line("  <fg=green>✓</> Renamed <fg=gray>{$old}</> to <fg=white>{$name}</>");

        return self::SUCCESS;
    }
}
