<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\Template;
use App\Data\Install\InstallContext;
use App\Services\ConfigManager;
use App\Services\Install\InstallLogger;
use App\Services\Install\InstallPipeline;
use App\Services\TemplateRegistry;
use HardImpact\Orbit\Core\Models\Setting;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class InstallCommand extends Command
{
    protected $signature = 'install
        {--tld=test : Top-level domain for local sites}
        {--php-versions=8.5 : PHP versions to install (comma-separated)}
        {--skip-docker : Skip Docker/OrbStack installation}
        {--skip-trust : Skip SSL certificate trust}
        {--template= : Installation template}
        {--services= : Additional Docker services (comma-separated)}
        {--node-packages= : Node package managers (comma-separated: npm,yarn,pnpm,bun)}
        {--yes : Non-interactive mode}';

    protected $description = 'Install Orbit and configure your development environment';

    public function __construct(
        private readonly TemplateRegistry $registry,
        private readonly InstallPipeline $pipeline,
        private readonly ConfigManager $configManager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->confirmReinstall()) {
            return self::SUCCESS;
        }

        $template = $this->resolveTemplate();

        if (! $template) {
            return self::FAILURE;
        }

        $tld = $this->resolveTld($template);
        $phpVersions = $this->resolvePhpVersions();
        $nodePackages = $this->resolveNodePackages($template);

        $options = $this->options();
        $options['tld'] = $tld;
        $options['php-versions'] = $phpVersions;
        $options['node-packages'] = $nodePackages !== [] ? implode(',', $nodePackages) : '';

        $context = InstallContext::fromOptions($options, $template->name());
        $logger = new InstallLogger($this);

        $platform = PHP_OS_FAMILY === 'Darwin' ? 'macOS' : 'Linux';

        $logger->title('Installing Orbit');
        $this->line("  <fg=gray>Template</>   {$template->label()}");
        $this->line("  <fg=gray>Platform</>   {$platform}");

        if ($template->name() !== 'php-production') {
            $this->line("  <fg=gray>TLD</>        .{$context->tld}");
        }

        $this->line('  <fg=gray>PHP</>        '.implode(', ', $context->phpVersions));

        if ($context->services !== []) {
            $this->line('  <fg=gray>Services</>   '.implode(', ', $context->services));
        }

        if ($context->nodePackageManagers !== []) {
            $this->line('  <fg=gray>Node PKGs</>  '.implode(', ', $context->nodePackageManagers));
        }

        $result = $this->pipeline->run($template, PHP_OS_FAMILY, $context, $logger);

        if ($result->isFailed()) {
            $logger->newLine();
            $logger->error('Installation failed: '.$result->error);

            return self::FAILURE;
        }

        Setting::set('installed_template', $template->name());
        Setting::set('installed_at', now()->toIso8601String());
        $this->configManager->setTemplate($template->name());

        $this->newLine();
        $this->line('<fg=green;options=bold>Orbit installed successfully!</>');
        $this->newLine();

        if (! $context->nonInteractive && PHP_OS_FAMILY === 'Darwin') {
            $herdInstalled = is_dir('/Applications/Herd.app') || is_dir(getenv('HOME').'/Applications/Herd.app');
            if ($herdInstalled) {
                $logger->warn('Important: Configure your shell PATH');
                $logger->info('To use Orbit\'s PHP instead of Herd, add to your ~/.zshrc:');
                $logger->info('  export PATH="/opt/homebrew/bin:$PATH"');
                $logger->info('Then run: <fg=cyan>source ~/.zshrc</>');
                $logger->newLine();
            }
        }

        return self::SUCCESS;
    }

    private function confirmReinstall(): bool
    {
        $installedTemplate = Setting::get('installed_template');

        if ($installedTemplate === null) {
            return true;
        }

        if ($installedTemplate === 'development') {
            $installedTemplate = 'php-dev';
            Setting::set('installed_template', 'php-dev');
        }

        if ($installedTemplate === 'php') {
            $installedTemplate = 'php-dev';
            Setting::set('installed_template', 'php-dev');
        }

        $this->warn('⚠️  Orbit is already installed on this machine.');
        $this->line("   Installed template: <fg=cyan>{$installedTemplate}</fg=cyan>");
        $this->newLine();

        if ($this->option('yes')) {
            $this->info('Rerunning installation to fix potential misconfigurations...');
            $this->newLine();

            return true;
        }

        $action = select(
            label: 'What would you like to do?',
            options: [
                'rerun' => '🔧 Rerun installation (fix misconfigurations)',
                'cancel' => '❌ Cancel (keep current installation)',
            ],
            default: 'rerun',
        );

        if ($action === 'cancel') {
            $this->newLine();
            $this->info('✓ Installation cancelled. Current installation remains intact.');

            return false;
        }

        $this->newLine();

        return true;
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

        if ($available === []) {
            $this->error('No templates available for '.PHP_OS_FAMILY);

            return null;
        }

        if ($this->option('yes')) {
            return $this->registry->get('php-dev');
        }

        $choices = [];
        foreach ($available as $t) {
            $choices[$t->name()] = "{$t->label()} - {$t->description()}";
        }

        $name = select(
            label: 'Select an installation template',
            options: $choices,
            default: 'php-dev',
        );

        return $this->registry->get($name);
    }

    private function resolveTld(Template $template): string
    {
        if ($template->name() === 'php-production') {
            return 'test';
        }

        $inputTld = $this->option('tld');
        if ($inputTld !== 'test') {
            return $inputTld;
        }

        if ($this->option('yes')) {
            return 'test';
        }

        return text(
            label: 'What TLD would you like to use for local sites?',
            placeholder: 'test',
            default: 'test',
            hint: 'Examples: test, local, dev, orbit',
            validate: fn (string $value) => $this->validateTld($value),
        );
    }

    /**
     * @return array<int, string>
     */
    private function resolvePhpVersions(): array
    {
        $explicit = $this->option('php-versions');

        if ($explicit !== '8.5' || $this->option('yes')) {
            return array_map(trim(...), explode(',', $explicit));
        }

        return multiselect(
            label: 'Which PHP versions would you like to install?',
            options: ['8.1', '8.2', '8.3', '8.4', '8.5'],
            default: ['8.5'],
            required: true,
        );
    }

    /**
     * @return array<int, string>
     */
    private function resolveNodePackages(Template $template): array
    {
        if ($template->name() === 'gateway') {
            return [];
        }

        $explicit = $this->option('node-packages');

        if ($explicit !== null) {
            return $explicit !== '' ? array_map(trim(...), explode(',', $explicit)) : [];
        }

        if ($this->option('yes')) {
            return [];
        }

        return multiselect(
            label: 'Which Node package managers would you like to install?',
            options: [
                'bun' => 'Bun',
                'npm' => 'NPM (includes Node)',
                'yarn' => 'Yarn (requires NPM)',
                'pnpm' => 'pnpm (requires NPM)',
            ],
            hint: 'Leave empty for none',
        );
    }

    private function validateTld(string $tld): ?string
    {
        $tld = ltrim($tld, '.');

        if ($tld === '') {
            return 'TLD cannot be empty.';
        }

        if (strlen($tld) > 63) {
            return 'TLD is too long (max 63 characters).';
        }

        if (! preg_match('/^[a-zA-Z0-9-]+$/', $tld)) {
            return 'TLD can only contain letters, numbers, and hyphens.';
        }

        $reserved = ['localhost', 'localdomain', 'domain', 'example', 'invalid', 'test'];
        if (in_array(strtolower($tld), $reserved, true)) {
            if ($tld !== 'test') {
                return "'{$tld}' is a reserved TLD. Please choose a different one.";
            }
        }

        $commonTlds = ['com', 'org', 'net', 'io', 'dev', 'app', 'co', 'me', 'info'];
        if (in_array(strtolower($tld), $commonTlds, true)) {
            return "'{$tld}' is a real TLD. Using it for local development may cause conflicts.";
        }

        return null;
    }
}
