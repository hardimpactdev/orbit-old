<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\SupportsJsonMode;
use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use LaravelZero\Framework\Commands\Command;

final class UnlinkCommand extends Command
{
    use SupportsJsonMode, WithJsonOutput;

    protected $signature = 'unlink
        {name : The linked project slug to remove}
        {--json : Output as JSON}';

    protected $description = 'Unlink a previously linked project';

    public function handle(ConfigManager $config): int
    {
        $slug = $this->argument('name');
        $overrides = $config->getSiteOverrides();

        if (! isset($overrides[$slug]) || ! isset($overrides[$slug]['path'])) {
            return $this->failWithMessage("No linked project found for '{$slug}'.");
        }

        $config->removeSiteOverride($slug);

        $reloadExitCode = $this->callSilentlyWhenJson('caddy:reload');

        if ($reloadExitCode !== self::SUCCESS) {
            return $this->failWithMessage("Unlinked '{$slug}' but failed to reload Caddy.");
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'slug' => $slug,
                'message' => "Project '{$slug}' unlinked.",
            ]);
        }

        $this->info("Unlinked <comment>{$slug}</comment>");

        return self::SUCCESS;
    }
}
