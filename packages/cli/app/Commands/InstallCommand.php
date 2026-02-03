<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\Template;
use App\Data\Install\InstallContext;
use App\Services\ConfigManager;
use App\Services\Install\InstallLogger;
use App\Services\Install\InstallPipeline;
use App\Services\TemplateRegistry;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class InstallCommand extends Command
{
    protected $signature = 'install
        {--tld=test : Top-level domain for local sites}
        {--php-versions=8.4,8.5 : PHP versions to install (comma-separated)}
        {--skip-docker : Skip Docker/OrbStack installation}
        {--skip-trust : Skip SSL certificate trust}
        {--template= : Installation template}
        {--yes : Non-interactive mode}';

    protected $description = 'Install Orbit and configure your development environment';

    private const MIN_PHP_VERSION = '8.4.0';

    public function __construct(
        private readonly TemplateRegistry $registry,
        private readonly InstallPipeline $pipeline,
        private readonly ConfigManager $configManager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->validatePrerequisites()) {
            return self::FAILURE;
        }

        $template = $this->resolveTemplate();

        if (! $template) {
            return self::FAILURE;
        }

        $context = InstallContext::fromOptions($this->options(), $template->name());
        $logger = new InstallLogger($this);

        $platform = PHP_OS_FAMILY === 'Darwin' ? 'macOS' : 'Linux';

        $logger->title('Installing Orbit');
        $logger->info("Template: {$template->label()}");
        $logger->info("Platform: {$platform}");
        $logger->info("TLD: .{$context->tld}");
        $logger->info('PHP versions: '.implode(', ', $context->phpVersions));
        $logger->newLine();

        $result = $this->pipeline->run($template, PHP_OS_FAMILY, $context, $logger);

        if ($result->isFailed()) {
            $logger->newLine();
            $logger->error('Installation failed: '.$result->error);

            return self::FAILURE;
        }

        $this->configManager->setTemplate($template->name());

        $logger->newLine();
        $logger->success('Orbit installed successfully!');
        $logger->newLine();
        $logger->info("Dashboard: https://orbit.{$context->tld}");
        $logger->info('Create a project: orbit project:create myapp');

        return self::SUCCESS;
    }

    private function resolveTemplate(): ?Template
    {
        $name = $this->option('template');

        if ($name) {
            if (! $this->registry->has($name)) {
                $this->error("Unknown template: {$name}");
                $this->line('');
                $this->line('Available templates:');
                foreach ($this->registry->forPlatform(PHP_OS_FAMILY) as $t) {
                    $this->line("  - {$t->name()}: {$t->description()}");
                }

                return null;
            }

            $template = $this->registry->get($name);

            if (! $template->supportsPlatform(PHP_OS_FAMILY)) {
                $this->error("Template '{$name}' does not support ".PHP_OS_FAMILY);

                return null;
            }

            return $template;
        }

        $available = $this->registry->forPlatform(PHP_OS_FAMILY);

        if (count($available) === 1) {
            return reset($available);
        }

        if ($this->option('yes')) {
            return $this->registry->get('development');
        }

        $choices = [];
        foreach ($available as $t) {
            $choices[$t->name()] = "{$t->label()} - {$t->description()}";
        }

        $selected = $this->choice('Select installation template', array_values($choices));

        $name = array_search($selected, $choices, true);

        return $this->registry->get($name);
    }

    private function validatePrerequisites(): bool
    {
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            $this->error('PHP '.self::MIN_PHP_VERSION.'+ is required. Current version: '.PHP_VERSION);
            $this->line('');
            $this->line('Run the bootstrap installer to install prerequisites:');
            $this->info('  curl -fsSL https://raw.githubusercontent.com/hardimpactdev/orbit-cli/main/install.sh | bash');

            return false;
        }

        $composerCheck = Process::run('composer --version');
        if (! $composerCheck->successful()) {
            $this->error('Composer is required but not found.');
            $this->line('');
            $this->line('Run the bootstrap installer to install prerequisites:');
            $this->info('  curl -fsSL https://raw.githubusercontent.com/hardimpactdev/orbit-cli/main/install.sh | bash');

            return false;
        }

        return true;
    }
}
