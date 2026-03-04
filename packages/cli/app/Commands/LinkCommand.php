<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\SupportsJsonMode;
use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

final class LinkCommand extends Command
{
    use SupportsJsonMode, WithJsonOutput;

    protected $signature = 'link
        {name? : The slug to serve the project as (e.g. my-project → https://my-project.bear)}
        {--path= : Path to the project directory (defaults to current directory)}
        {--json : Output as JSON}';

    protected $description = 'Link a directory as a Caddy-served project with SSL';

    public function handle(ConfigManager $config): int
    {
        $path = realpath($this->option('path') ?? getcwd());

        if (! $path || ! File::isDirectory($path)) {
            return $this->failWithMessage('Directory does not exist: '.($this->option('path') ?? getcwd()));
        }

        $slug = $this->resolveSlug($path, $config);

        $config->set("sites.{$slug}", ['path' => $path]);

        $reloadExitCode = $this->callSilentlyWhenJson('caddy:reload');

        if ($reloadExitCode !== self::SUCCESS) {
            return $this->failWithMessage('Linked project but failed to reload Caddy.');
        }

        $tld = $config->getTld();
        $url = "https://{$slug}.{$tld}";

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'slug' => $slug,
                'path' => $path,
                'url' => $url,
            ]);
        }

        $this->info("Linked <comment>{$slug}</comment> → {$path}");
        $this->line("  <fg=gray>{$url}</>");

        return self::SUCCESS;
    }

    private function resolveSlug(string $path, ConfigManager $config): string
    {
        if ($name = $this->argument('name')) {
            return Str::slug($name);
        }

        $envSlug = $this->slugFromEnv($path, $config->getTld());
        if ($envSlug) {
            return $envSlug;
        }

        return Str::slug(basename($path));
    }

    private function slugFromEnv(string $path, string $tld): ?string
    {
        $envPath = $path.'/.env';

        if (! File::exists($envPath)) {
            return null;
        }

        $content = File::get($envPath);

        if (! preg_match('/^APP_URL=(.+)$/m', $content, $matches)) {
            return null;
        }

        $url = trim($matches[1], "\"' ");
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host || ! str_ends_with($host, ".{$tld}")) {
            return null;
        }

        // Extract subdomain: "my-project.bear" → "my-project"
        $subdomain = Str::beforeLast($host, ".{$tld}");

        return $subdomain ?: null;
    }
}
