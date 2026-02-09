# Commands Directory

Artisan console commands for the Orbit CLI.

## Structure

```
Commands/
├── Host/        # Host system management (systemd, launchd)
├── Service/     # Docker service commands
├── Setup/       # Initial setup wizards
└── *.php        # Top-level commands
```

## Command Patterns

### Basic Structure

```php
<?php

declare(strict_types=1);

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

final class MyCommand extends Command
{
    protected $signature = 'my:command
        {name : Required argument}
        {--flag : Boolean flag}
        {--option= : Option with value}
        {--json : Output as JSON}';

    protected $description = 'What this command does';

    public function handle(
        ConfigManager $config,
        SomeService $service,
    ): int {
        // Use dependency injection for services
        return self::SUCCESS;
    }
}
```

### JSON Output Trait

Use `WithJsonOutput` for commands that support `--json`:

```php
use App\Concerns\WithJsonOutput;

final class MyCommand extends Command
{
    use WithJsonOutput;

    public function handle(): int
    {
        // When --json is used, this outputs clean JSON
        return $this->outputJson(['status' => 'success', 'data' => $result]);
    }
}
```

### Step Output Trait

Use `HasStepOutput` for commands with provisioning-style progress:

```php
use App\Concerns\HasStepOutput;

final class MyCommand extends Command
{
    use HasStepOutput;

    public function handle(): int
    {
        $this->step('Task completed');   // ✓ Task completed (green)
        $this->skip('Task skipped');     // ○ Task skipped (gray)
    }
}
```

### Command Delegation Pattern

For commands that share provisioning logic, use `$this->call()` to delegate:

```php
// SetupGatewayCommand delegates shared provisioning to setup:remote
$this->call('setup:remote', [
    'ip' => $ip,
    'user' => $user,
    '--template' => 'gateway',
    '--yes' => true,  // suppress interactive prompts since parent already collected input
]);
```

Pass `--yes` when the parent command already collected interactive input to avoid double-prompting.

## Key Commands

| Command | Description |
|---------|-------------|
| `install` | Install Orbit using a template (`--template=php-dev`) |
| `setup` | Interactive wizard: local or remote setup |
| `setup:remote` | Provision remote server + install any template |
| `setup:gateway` | Gateway setup (delegates to `setup:remote` + gateway post-install) |
| `site:create` | Create new site via ProvisionPipeline |
| `site:delete` | Remove site and cleanup resources |
| `start`/`stop`/`restart` | Host + Docker service lifecycle |
| `status` | Show running services |
| `php` | Manage PHP versions |

### Remote Setup Architecture

```
setup (wizard)
├── Local → install --template=X
└── Remote
    ├── Gateway → setup:gateway → setup:remote --template=gateway → post-install (WG, DB)
    └── Other   → setup:remote --template=X
```

`setup:remote` handles: clearHostKey → detectState → checkSystemCompatibility → createUser → copySshKeys → hardenSsh → updateSystem → installOrbit → [Docker if needed] → runMigrations → installTemplate

Docker is installed conditionally:
- `gateway`, `php-dev`: always
- `php-production`: only when `--services` is non-empty

## Site Creation Architecture

The `site:create` command runs provisioning synchronously with real-time output:

```php
// Create Site record in database
$site = Site::create([...]);

// Run ProvisionPipeline synchronously
$pipeline = app(ProvisionPipeline::class);
$pipeline->run($context);
```

Provisioning is handled by `orbit-core`'s `ProvisionPipeline` with real-time console output.

## Gotcha: JSON Output Must Be Clean

When `--json` flag is used, callers parse stdout as JSON. Any non-JSON output corrupts parsing.

```php
// Only output to console when not in JSON mode
if (!$this->wantsJson()) {
    $this->info('Processing...');
}

// Use NullOutput for nested Artisan calls
$output = $this->wantsJson()
    ? new \Symfony\Component\Console\Output\NullOutput()
    : $this->output;
Artisan::call('some:command', $args, $output);
```

## Signal Handling

Long-running commands should handle termination signals:

```php
if (extension_loaded('pcntl')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, fn () => $this->abort('Terminated'));
    pcntl_signal(SIGINT, fn () => $this->abort('Interrupted'));
}
```
