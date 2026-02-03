# Component + Prepare Architecture

## Summary

Add a Component abstraction with dedicated Prepare classes to the install pipeline. Each component (Docker, PHP, Caddy, DNS) has a `prepare()` method returning a dedicated prepare class that runs read-only validation before any system mutations occur. Templates collect prepare steps from all their components. The pipeline runs all prepare checks first, aborting if any fail. This also enables future single-component installation by calling `prepare()` + `installSteps()` on a single component.

---

## New Files

### 1. Component Contract

**File:** `packages/cli/app/Contracts/Component.php`

```php
<?php

declare(strict_types=1);

namespace App\Contracts;

interface Component
{
    public function name(): string;

    public function label(): string;

    /**
     * @return class-string|null
     */
    public function prepare(string $osFamily): ?string;

    /**
     * @return array<array{action: class-string, name: string}>
     */
    public function installSteps(string $osFamily): array;

    public function supportsPlatform(string $osFamily): bool;
}
```

- `prepare()` returns a prepare class name or `null` (components without preparation needs)
- `installSteps()` returns steps needed to install this component standalone (for future single-component install)
- Platform-specific branching happens inside each method via `match ($osFamily)`

---

### 2. Component Implementations

All in `packages/cli/app/Components/`. Each is `final readonly`, follows existing codebase patterns.

#### `DockerComponent.php`

```php
public function prepare(string $osFamily): ?string
{
    return match ($osFamily) {
        'Darwin' => Mac\PrepareDocker::class,
        'Linux' => Linux\PrepareDocker::class,
        default => null,
    };
}

public function installSteps(string $osFamily): array
{
    return match ($osFamily) {
        'Darwin' => [
            ['action' => Mac\InstallOrbStack::class, 'name' => 'Installing OrbStack'],
            ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
            ['action' => Shared\BuildDockerImages::class, 'name' => 'Building Docker images'],
            ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],
            ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],
            ['action' => Shared\StartServices::class, 'name' => 'Starting services'],
        ],
        'Linux' => [
            ['action' => Linux\InstallDocker::class, 'name' => 'Installing Docker'],
            ['action' => Shared\CreateDockerNetwork::class, 'name' => 'Creating Docker network'],
            ['action' => Shared\BuildDockerImages::class, 'name' => 'Building Docker images'],
            ['action' => Shared\PullServiceImages::class, 'name' => 'Pulling service images'],
            ['action' => Shared\InitializeServices::class, 'name' => 'Initializing services'],
            ['action' => Shared\StartServices::class, 'name' => 'Starting services'],
        ],
        default => [],
    };
}
```

#### `PhpComponent.php`

```php
public function prepare(string $osFamily): ?string
{
    return match ($osFamily) {
        'Darwin' => Mac\PreparePhp::class,
        'Linux' => Linux\PreparePhp::class,
        default => null,
    };
}

public function installSteps(string $osFamily): array
{
    return match ($osFamily) {
        'Darwin' => [
            ['action' => Mac\ConfigurePhpFpm::class, 'name' => 'Configuring PHP-FPM'],
        ],
        'Linux' => [
            ['action' => Linux\ConfigurePhpFpm::class, 'name' => 'Configuring PHP-FPM'],
        ],
        default => [],
    };
}
```

#### `CaddyComponent.php`

```php
public function prepare(string $osFamily): ?string
{
    return match ($osFamily) {
        'Darwin' => Mac\PrepareCaddy::class,
        'Linux' => Linux\PrepareCaddy::class,
        default => null,
    };
}

public function installSteps(string $osFamily): array
{
    return match ($osFamily) {
        'Darwin' => [
            ['action' => Mac\InstallCaddy::class, 'name' => 'Installing Caddy'],
            ['action' => Shared\GenerateCaddyfile::class, 'name' => 'Generating Caddyfile'],
            ['action' => Mac\TrustRootCa::class, 'name' => 'Trusting SSL certificate'],
        ],
        'Linux' => [
            ['action' => Linux\InstallCaddy::class, 'name' => 'Installing Caddy'],
            ['action' => Shared\GenerateCaddyfile::class, 'name' => 'Generating Caddyfile'],
            ['action' => Linux\TrustRootCa::class, 'name' => 'Trusting SSL certificate'],
        ],
        default => [],
    };
}
```

#### `DnsComponent.php`

```php
public function prepare(string $osFamily): ?string
{
    return match ($osFamily) {
        'Darwin' => Mac\PrepareDns::class,
        'Linux' => Linux\PrepareDns::class,
        default => null,
    };
}

public function installSteps(string $osFamily): array
{
    return match ($osFamily) {
        'Darwin' => [
            ['action' => Shared\GenerateDnsConfig::class, 'name' => 'Generating DNS config'],
            ['action' => Shared\ConfigureHostsFile::class, 'name' => 'Configuring /etc/hosts'],
            ['action' => Mac\ConfigureDns::class, 'name' => 'Configuring DNS'],
        ],
        'Linux' => [
            ['action' => Shared\GenerateDnsConfig::class, 'name' => 'Generating DNS config'],
            ['action' => Shared\ConfigureHostsFile::class, 'name' => 'Configuring /etc/hosts'],
            ['action' => Linux\ConfigureDns::class, 'name' => 'Configuring DNS'],
        ],
        default => [],
    };
}
```

---

### 3. Prepare Actions

All in `packages/cli/app/Actions/Prepare/{Mac,Linux}/`. Same signature as install actions: `handle(InstallContext $context, InstallLogger $logger): StepResult`. All checks are **read-only** — no filesystem writes, no package installs, no service restarts.

#### Mac Prepare Classes (`app/Actions/Prepare/Mac/`)

**`PrepareDocker.php`** — Checks:
- Docker/OrbStack installed and accessible
- Required ports available (5432 postgres, 6379 redis, 8025 mailpit)
- Docker socket accessible

**`PreparePhp.php`** — Checks:
- Homebrew available, shivammathur/php tap accessible
- Requested PHP versions are valid formulae
- Port 9000 not in use (default FPM conflict)
- No existing Orbit FPM socket conflicts

**`PrepareCaddy.php`** — Checks:
- Ports 80 and 443 available
- No competing web server running (nginx, apache)

**`PrepareDns.php`** — Checks:
- Port 53 available for dnsmasq
- No conflicting `/etc/resolver/{tld}` files from other tools
- `/etc/resolver/` directory exists or is creatable

#### Linux Prepare Classes (`app/Actions/Prepare/Linux/`)

**`PrepareDocker.php`** — Checks:
- Docker installed or installable
- Required ports available (5432, 6379, 8025)
- No conflicting Docker daemon

**`PreparePhp.php`** — Checks:
- apt available, ondrej/php PPA accessible
- Requested PHP versions valid
- Port 9000 not in use
- No existing Orbit FPM socket conflicts

**`PrepareCaddy.php`** — Checks:
- Ports 80 and 443 available
- No competing web server running (nginx, apache)
- No conflicting systemd service

**`PrepareDns.php`** — Checks:
- Port 53 available
- systemd-resolved status (may need disabling)
- resolv.conf writable

---

## Modified Files

### 4. Template Interface

**File:** `packages/cli/app/Contracts/Template.php`

Add two methods:

```php
/**
 * @return array<Component>
 */
public function components(string $osFamily): array;

/**
 * @return array<array{action: class-string, name: string}>
 */
public function prepareSteps(string $osFamily): array;
```

### 5. DevelopmentTemplate

**File:** `packages/cli/app/Templates/DevelopmentTemplate.php`

Add constructor DI for the 4 components:

```php
public function __construct(
    private readonly DockerComponent $docker,
    private readonly PhpComponent $php,
    private readonly CaddyComponent $caddy,
    private readonly DnsComponent $dns,
) {}
```

Add `components()`:

```php
public function components(string $osFamily): array
{
    return array_filter(
        [$this->docker, $this->php, $this->caddy, $this->dns],
        fn (Component $c) => $c->supportsPlatform($osFamily),
    );
}
```

Add `prepareSteps()` — loops components, collects non-null prepare classes:

```php
public function prepareSteps(string $osFamily): array
{
    $steps = [];

    foreach ($this->components($osFamily) as $component) {
        $prepareClass = $component->prepare($osFamily);

        if ($prepareClass === null) {
            continue;
        }

        $steps[] = [
            'action' => $prepareClass,
            'name' => "Checking {$component->label()}",
        ];
    }

    return $steps;
}
```

**Keep existing `installSteps()` and `macSteps()`/`linuxSteps()` unchanged.** The hand-crafted ordering handles interleaved dependencies correctly (e.g., directories must exist before docker-compose generation). Components' `installSteps()` are for future single-component install, not used by the template's full install flow.

### 6. TemplateRegistry

**File:** `packages/cli/app/Services/TemplateRegistry.php`

Change `new DevelopmentTemplate` to `app(DevelopmentTemplate::class)` so the container can inject components:

```php
public function __construct()
{
    $this->register(app(DevelopmentTemplate::class));
}
```

### 7. InstallPipeline

**File:** `packages/cli/app/Services/Install/InstallPipeline.php`

Add preparation phase before install phase:

```php
public function run(Template $template, string $osFamily, InstallContext $context, InstallLogger $logger): StepResult
{
    // Phase 1: Preparation (read-only validation)
    $prepareSteps = $template->prepareSteps($osFamily);

    if (count($prepareSteps) > 0) {
        $total = count($prepareSteps);

        foreach ($prepareSteps as $index => $step) {
            $logger->progress($index + 1, $total, $step['name']);

            $result = app($step['action'])->handle($context, $logger);

            if ($result->isFailed()) {
                return $result;
            }
        }

        $logger->newLine();
        $logger->success('All prerequisites verified');
        $logger->newLine();
    }

    // Phase 2: Installation (existing logic, unchanged)
    $steps = $template->installSteps($osFamily);
    $total = count($steps);

    foreach ($steps as $index => $step) {
        $logger->progress($index + 1, $total, $step['name']);

        $result = app($step['action'])->handle($context, $logger);

        if ($result->isFailed()) {
            return $result;
        }
    }

    return StepResult::success();
}
```

---

## Pipeline Output

```
[1/4] Checking Docker Runtime
  ✓ OrbStack installed
  ✓ Ports 5432, 6379, 8025 available
[2/4] Checking PHP-FPM
  ✓ PHP 8.4, 8.5 available via Homebrew
  ✓ Port 9000 available
[3/4] Checking Caddy Web Server
  ✓ Ports 80, 443 available
[4/4] Checking DNS Resolver
  ✓ Port 53 available
  ✓ No TLD conflicts

  ✓ All prerequisites verified

[1/19] Checking prerequisites
[2/19] Installing OrbStack
...
```

---

## Future: Single-Component Install

Once this architecture exists, adding `orbit install:component docker` becomes:

```php
$component = app(DockerComponent::class);

$prepareClass = $component->prepare(PHP_OS_FAMILY);
if ($prepareClass) {
    $result = app($prepareClass)->handle($context, $logger);
    if ($result->isFailed()) return self::FAILURE;
}

foreach ($component->installSteps(PHP_OS_FAMILY) as $step) {
    $result = app($step['action'])->handle($context, $logger);
    if ($result->isFailed()) return self::FAILURE;
}
```

---

## File Tree

```
packages/cli/app/
├── Contracts/
│   ├── Template.php                    ← modified (add components, prepareSteps)
│   └── Component.php                   ← new
├── Components/
│   ├── DockerComponent.php             ← new
│   ├── PhpComponent.php                ← new
│   ├── CaddyComponent.php             ← new
│   └── DnsComponent.php               ← new
├── Actions/
│   ├── Install/                        ← existing, unchanged
│   │   ├── Mac/
│   │   ├── Linux/
│   │   └── Shared/
│   └── Prepare/                        ← new directory
│       ├── Mac/
│       │   ├── PrepareDocker.php       ← new
│       │   ├── PreparePhp.php          ← new
│       │   ├── PrepareCaddy.php        ← new
│       │   └── PrepareDns.php          ← new
│       └── Linux/
│           ├── PrepareDocker.php       ← new
│           ├── PreparePhp.php          ← new
│           ├── PrepareCaddy.php        ← new
│           └── PrepareDns.php          ← new
├── Templates/
│   └── DevelopmentTemplate.php         ← modified (constructor DI, components, prepareSteps)
└── Services/
    ├── TemplateRegistry.php            ← modified (container resolution)
    └── Install/
        └── InstallPipeline.php         ← modified (add prepare phase)
```

**New files:** 13 (1 contract, 4 components, 8 prepare actions)
**Modified files:** 4 (Template interface, DevelopmentTemplate, TemplateRegistry, InstallPipeline)

---

## Implementation Order

1. `app/Contracts/Component.php` — interface
2. `app/Actions/Prepare/Mac/` — 4 prepare classes
3. `app/Actions/Prepare/Linux/` — 4 prepare classes
4. `app/Components/` — 4 component classes
5. `app/Contracts/Template.php` — add new methods
6. `app/Templates/DevelopmentTemplate.php` — constructor DI + new methods
7. `app/Services/TemplateRegistry.php` — container resolution
8. `app/Services/Install/InstallPipeline.php` — prepare phase

## Verification

```bash
./vendor/bin/pest
./vendor/bin/phpstan analyse --memory-limit=512M
./vendor/bin/pint --test
```
