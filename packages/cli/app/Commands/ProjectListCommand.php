<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithHumanOutput;
use App\Concerns\WithJsonOutput;
use App\Services\ConfigManager;
use App\Services\ProjectScanner;
use LaravelZero\Framework\Commands\Command;

final class ProjectListCommand extends Command
{
    use WithHumanOutput;
    use WithJsonOutput;

    protected $signature = 'project:list {--json : Output as JSON}';

    protected $description = 'List ALL directories in scan paths as projects';

    public function handle(ProjectScanner $projectScanner, ConfigManager $configManager): int
    {
        $projects = $projectScanner->scan();
        $tld = $configManager->getTld();
        $defaultPhp = $configManager->getDefaultPhpVersion();

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'projects' => $projects,
                'count' => count($projects),
                'tld' => $tld,
                'default_php_version' => $defaultPhp,
            ]);
        }

        if (empty($projects)) {
            $this->warn('No projects found. Add paths to your config.json file.');

            return self::SUCCESS;
        }

        $tableData = array_map(fn ($project) => [
            'name' => $project['name'],
            'has_public' => $project['has_public_folder'],
            'domain' => $project['domain'] ?? null,
            'php' => $project['php_version'].($project['has_custom_php'] ? ' (custom)' : ''),
        ], $projects);

        $this->renderForHumans($tableData, 'Projects');

        $this->formatter()->renderKeyValue([
            'tld' => $tld,
            'default_php' => $defaultPhp,
            'total' => count($projects).' projects',
        ]);

        return self::SUCCESS;
    }
}
