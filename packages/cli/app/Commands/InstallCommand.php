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

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

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

    public function __construct(
        private readonly TemplateRegistry $registry,
        private readonly InstallPipeline $pipeline,
        private readonly ConfigManager $configManager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Check if already installed and prompt for rerun
        if (! $this->confirmReinstall()) {
            return self::SUCCESS;
        }

        $template = $this->resolveTemplate();

        if (! $template) {
            return self::FAILURE;
        }

        // Prompt for TLD in interactive mode
        $tld = $this->resolveTld();

        $options = $this->options();
        $options['tld'] = $tld;

        $context = InstallContext::fromOptions($options, $template->name());
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

        // Store installed template in database for tracking
        Setting::set('installed_template', $template->name());
        Setting::set('installed_at', now()->toIso8601String());
        $this->configManager->setTemplate($template->name());

        $logger->newLine();
        $logger->success('Orbit installed successfully!');
        $logger->newLine();

        // Show PATH configuration notice for macOS users
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

    /**
     * Check if Orbit is already installed and prompt for reinstall.
     */
    private function confirmReinstall(): bool
    {
        $installedTemplate = Setting::get('installed_template');

        if ($installedTemplate === null) {
            return true;
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

        // Always show template selection in interactive mode
        if ($this->option('yes')) {
            return $this->registry->get('development');
        }

        $choices = [];
        foreach ($available as $t) {
            $choices[$t->name()] = "{$t->label()} - {$t->description()}";
        }

        $name = select(
            label: 'Select an installation template',
            options: $choices,
            default: 'development',
        );

        return $this->registry->get($name);
    }

    /**
     * Resolve the TLD to use for the installation.
     */
    private function resolveTld(): string
    {
        // If --tld was explicitly provided (not the default), use it
        $inputTld = $this->option('tld');
        if ($inputTld !== 'test') {
            return $inputTld;
        }

        // In non-interactive mode, use the default
        if ($this->option('yes')) {
            return 'test';
        }

        // Prompt for TLD in interactive mode
        return text(
            label: 'What TLD would you like to use for local sites?',
            placeholder: 'test',
            default: 'test',
            hint: 'Examples: test, local, dev, orbit',
            validate: fn (string $value) => $this->validateTld($value),
        );
    }

    /**
     * Validate the TLD format.
     */
    private function validateTld(string $tld): ?string
    {
        // Remove leading dot if present
        $tld = ltrim($tld, '.');

        // Check for empty TLD
        if ($tld === '') {
            return 'TLD cannot be empty.';
        }

        // Check length (TLDs should be 1-63 characters)
        if (strlen($tld) > 63) {
            return 'TLD is too long (max 63 characters).';
        }

        // Check for valid characters (alphanumeric and hyphens only)
        if (! preg_match('/^[a-zA-Z0-9-]+$/', $tld)) {
            return 'TLD can only contain letters, numbers, and hyphens.';
        }

        // Check for reserved/restricted TLDs
        $reserved = ['localhost', 'localdomain', 'domain', 'example', 'invalid', 'test'];
        if (in_array(strtolower($tld), $reserved, true)) {
            // Allow 'test' as it's the default and commonly used
            if ($tld !== 'test') {
                return "'{$tld}' is a reserved TLD. Please choose a different one.";
            }
        }

        // Check for common real TLDs that might cause issues
        $commonTlds = ['com', 'org', 'net', 'io', 'dev', 'app', 'co', 'me', 'info'];
        if (in_array(strtolower($tld), $commonTlds, true)) {
            return "'{$tld}' is a real TLD. Using it for local development may cause conflicts.";
        }

        return null;
    }
}
