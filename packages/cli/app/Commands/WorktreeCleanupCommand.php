<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\ProjectScanner;
use App\Services\WorktreeService;
use HardImpact\Orbit\Core\Support\ProjectHelper;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class WorktreeCleanupCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'worktree:cleanup
                            {site : Site/project slug}
                            {worktree : Worktree name}
                            {--delete-branch : Delete local branch after removing worktree}
                            {--delete-remote : Delete remote branch too (origin)}
                            {--json : Output as JSON}';

    protected $description = 'Remove a worktree and unlink its routing';

    public function handle(ProjectScanner $scanner, WorktreeService $worktrees): int
    {
        $site = (string) $this->argument('site');
        $name = (string) $this->argument('worktree');

        $sitePath = $scanner->findProjectPath($site);
        if (! $sitePath) {
            return $this->failWithMessage("Site not found: {$site}. Run: orbit project:scan");
        }
        $sitePath = ProjectHelper::expandPath($sitePath);
        $worktreePath = rtrim($sitePath, '/')."/.worktrees/{$name}";

        $steps = [];
        $branch = null;

        if (is_dir($worktreePath)) {
            $branchResult = Process::path($worktreePath)->timeout(30)->run('git branch --show-current');
            $branch = trim($branchResult->output()) !== '' ? trim($branchResult->output()) : null;
            $steps['detect_branch'] = [
                'success' => $branchResult->successful(),
                'branch' => $branch,
                'error' => trim($branchResult->errorOutput()),
            ];
        } else {
            $steps['detect_branch'] = ['success' => true, 'branch' => null, 'note' => 'worktree path missing'];
        }

        $unlink = $worktrees->unlinkWorktree($site, $name);
        $steps['unlink_routing'] = ['success' => true, 'changed' => $unlink];

        if (is_dir($worktreePath)) {
            $rm = Process::path($sitePath)->timeout(120)->run('git worktree remove --force '.escapeshellarg($worktreePath));
            $steps['remove_worktree'] = [
                'success' => $rm->successful(),
                'output' => trim($rm->output()),
                'error' => trim($rm->errorOutput()),
            ];

            if (! $rm->successful()) {
                return $this->out($site, $name, $worktreePath, false, $steps, 'git worktree remove failed');
            }
        } else {
            $steps['remove_worktree'] = ['success' => true, 'note' => 'already removed'];
        }

        $prune = Process::path($sitePath)->timeout(60)->run('git worktree prune');
        $steps['prune'] = [
            'success' => $prune->successful(),
            'output' => trim($prune->output()),
            'error' => trim($prune->errorOutput()),
        ];

        if ((bool) $this->option('delete-branch') && $branch !== null && $branch !== '') {
            $del = Process::path($sitePath)->timeout(60)->run('git branch -D '.escapeshellarg($branch));
            $steps['delete_branch'] = [
                'success' => $del->successful(),
                'branch' => $branch,
                'output' => trim($del->output()),
                'error' => trim($del->errorOutput()),
            ];

            if ((bool) $this->option('delete-remote')) {
                $remote = Process::path($sitePath)->timeout(60)->run('git push origin --delete '.escapeshellarg($branch));
                $steps['delete_remote_branch'] = [
                    'success' => $remote->successful(),
                    'branch' => $branch,
                    'output' => trim($remote->output()),
                    'error' => trim($remote->errorOutput()),
                ];
            }
        }

        return $this->out($site, $name, $worktreePath, true, $steps);
    }

    private function out(string $site, string $name, string $worktreePath, bool $success, array $steps, ?string $error = null): int
    {
        $payload = [
            'success' => $success,
            'site' => $site,
            'worktree' => $name,
            'worktree_path' => $worktreePath,
            'steps' => $steps,
        ];

        if ($error !== null) {
            $payload['error'] = $error;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($payload, $success ? self::SUCCESS : self::FAILURE);
        }

        if (! $success) {
            $this->error((string) ($payload['error'] ?? 'worktree cleanup failed'));
            return self::FAILURE;
        }

        $this->info("Worktree cleanup complete: {$name}");
        return self::SUCCESS;
    }

    private function failWithMessage(string $message): int
    {
        if ($this->wantsJson()) {
            return $this->outputJson(['success' => false, 'error' => $message], self::FAILURE);
        }
        $this->error($message);

        return self::FAILURE;
    }
}
