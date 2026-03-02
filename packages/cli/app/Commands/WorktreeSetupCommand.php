<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use App\Services\ProjectScanner;
use App\Services\WorktreeService;
use HardImpact\Orbit\Core\Support\ProjectHelper;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class WorktreeSetupCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'worktree:setup
                            {site : The site/project slug}
                            {worktree : The worktree name}
                            {--branch= : Branch name to create/checkout inside the worktree (required)}
                            {--base=main : Base ref/branch for worktree creation}
                            {--force : Re-run setup steps even if already prepared}
                            {--json : Output as JSON}';

    protected $description = 'Create and fully set up a worktree (routing + env + deps + migrations/seeders via composer setup)';

    public function handle(ProjectScanner $projects, ConfigManager $config, WorktreeService $worktrees): int
    {
        $site = (string) $this->argument('site');
        $name = (string) $this->argument('worktree');
        $branch = (string) ($this->option('branch') ?? '');
        $base = (string) ($this->option('base') ?? 'main');
        $force = (bool) $this->option('force');

        if ($branch === '') {
            return $this->failWithMessage('Missing required option: --branch');
        }

        $results = [
            'site' => $site,
            'worktree' => $name,
            'branch' => $branch,
            'base' => $base,
            'worktree_path' => null,
            'domain' => null,
            'changed' => [
                'worktree_created' => false,
                'routing_linked' => false,
                'env_written' => false,
            ],
            'steps' => [],
        ];

        try {
            $sitePath = $projects->findProjectPath($site);
            if (! $sitePath) {
                return $this->failWithMessage("Site not found: {$site}. Run: orbit project:scan", $results);
            }

            $sitePath = ProjectHelper::expandPath($sitePath);
            $worktreePath = rtrim($sitePath, '/')."/.worktrees/{$name}";
            $results['worktree_path'] = $worktreePath;

            $tld = $config->getTld();
            $results['domain'] = "{$name}.{$site}.{$tld}";

            // Step 1: Create/reuse worktree
            if (! is_dir($worktreePath)) {
                File::ensureDirectoryExists(dirname($worktreePath));

                $cmd = sprintf('git fetch --all --prune && git worktree add -b %s %s %s',
                    escapeshellarg($branch),
                    escapeshellarg($worktreePath),
                    escapeshellarg($base)
                );

                $r = Process::path($sitePath)->timeout(180)->run($cmd);
                $results['steps']['git_worktree'] = $this->procResult($r);

                if (! $r->successful()) {
                    return $this->failWithMessage('git worktree add failed', $results);
                }

                $results['changed']['worktree_created'] = true;
            } else {
                $results['steps']['git_worktree'] = ['skipped' => true, 'message' => 'worktree exists'];
            }

            // Step 2: Checkout/create branch inside worktree
            $r = Process::path($worktreePath)->timeout(60)->run('git checkout -B '.escapeshellarg($branch));
            $results['steps']['git_branch'] = $this->procResult($r);
            if (! $r->successful()) {
                return $this->failWithMessage('git checkout -B failed', $results);
            }

            // Step 3: Link routing (only reload if changed)
            $link = $worktrees->linkWorktreeIfMissing($site, $worktreePath, $name);
            $results['steps']['routing'] = $link;
            $results['changed']['routing_linked'] = (bool) $link['linked'];
            if (! $link['success']) {
                return $this->failWithMessage(($link['error'] ?? 'routing link failed'), $results);
            }

            // Step 4: Ensure .env exists + enforce SQLite
            $env = $this->ensureEnvAndSqlite($sitePath, $worktreePath, $results['domain']);
            $results['steps']['env'] = $env;
            $results['changed']['env_written'] = (bool) ($env['written'] ?? false);
            if (! ($env['success'] ?? false)) {
                return $this->failWithMessage($env['error'] ?? 'env setup failed', $results);
            }

            // Step 5: composer install
            if ($force || ! is_dir($worktreePath.'/vendor')) {
                $r = Process::path($worktreePath)->timeout(1200)->run('composer install --no-interaction');
                $results['steps']['composer_install'] = $this->procResult($r);
                if (! $r->successful()) {
                    return $this->failWithMessage('composer install failed', $results);
                }
            } else {
                $results['steps']['composer_install'] = ['skipped' => true, 'message' => 'vendor exists'];
            }

            // Step 6: composer setup (must run migrations + seeders)
            $r = Process::path($worktreePath)->timeout(1800)->run('composer setup');
            $results['steps']['composer_setup'] = $this->procResult($r);
            if (! $r->successful()) {
                return $this->failWithMessage('composer setup failed', $results);
            }

            return $this->okResult($results);

        } catch (\Throwable $e) {
            return $this->failWithMessage($e->getMessage(), $results);
        }
    }

    private function ensureEnvAndSqlite(string $sitePath, string $worktreePath, string $domain): array
    {
        $envPath = $worktreePath.'/.env';

        try {
            if (! File::exists($envPath)) {
                $src = null;
                if (File::exists($sitePath.'/.env')) {
                    $src = $sitePath.'/.env';
                } elseif (File::exists($worktreePath.'/.env.example')) {
                    $src = $worktreePath.'/.env.example';
                } elseif (File::exists($sitePath.'/.env.example')) {
                    $src = $sitePath.'/.env.example';
                }

                if (! $src) {
                    return ['success' => false, 'error' => 'No .env or .env.example found to bootstrap worktree env'];
                }

                File::copy($src, $envPath);
            }

            if (! is_writable($envPath)) {
                return ['success' => false, 'error' => '.env is not writable in worktree'];
            }

            $original = (string) File::get($envPath);
            $content = $original;
            $appUrl = 'https://'.$domain;
            $dbPath = rtrim($worktreePath, '/').'/database/database.sqlite';

            $content = $this->setEnvVar($content, 'APP_URL', $appUrl);
            $content = $this->setEnvVar($content, 'DB_CONNECTION', 'sqlite');
            $content = $this->setEnvVar($content, 'DB_DATABASE', $dbPath);

            File::ensureDirectoryExists($worktreePath.'/database');
            if (! File::exists($dbPath)) {
                File::put($dbPath, '');
            }

            $written = false;
            if ($content !== $original) {
                File::put($envPath, $content);
                $written = true;
            }

            return ['success' => true, 'written' => $written];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function setEnvVar(string $content, string $key, string $value): string
    {
        $pattern = '/^'.preg_quote($key, '/').'=.*/m';
        $line = $key.'='.$value;

        if (preg_match($pattern, $content)) {
            return (string) preg_replace($pattern, $line, $content);
        }

        $content = rtrim($content, "\n");

        return $content."\n{$line}\n";
    }

    /** @param \Illuminate\Contracts\Process\ProcessResult $r */
    private function procResult($r): array
    {
        $out = trim($r->output());
        $err = trim($r->errorOutput());

        $res = ['success' => $r->successful()];
        if ($out !== '') {
            $res['output'] = mb_substr($out, 0, 4000);
        }
        if (! $r->successful() && $err !== '') {
            $res['error'] = mb_substr($err, 0, 4000);
        }

        return $res;
    }

    private function okResult(array $results): int
    {
        if ($this->wantsJson()) {
            return $this->outputJson(array_merge(['success' => true], $results));
        }

        $this->info('Worktree setup complete.');
        $this->line('Worktree: '.$results['worktree_path']);
        $this->line('Domain: '.$results['domain']);

        return self::SUCCESS;
    }

    private function failWithMessage(string $message, array $results = []): int
    {
        if ($this->wantsJson()) {
            return $this->outputJson(array_merge(['success' => false, 'error' => $message], $results), self::FAILURE);
        }

        $this->error($message);

        return self::FAILURE;
    }
}
