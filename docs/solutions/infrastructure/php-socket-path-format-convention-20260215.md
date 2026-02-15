---
date: 2026-02-15
problem_type: configuration
component: PHP-FPM / Caddy
severity: medium
symptoms:
  - "502 Bad Gateway on all requests"
  - "Caddy error: dial unix /path/to/phpXX.sock: connect: no such file or directory"
  - "PHP version validation passes but deployment fails"
root_cause: "Socket path format uses php84.sock not php8.4.sock"
tags: [php-fpm, caddy, socket, validation]
---

# PHP-FPM Socket Path Format: No Dots in Filename

## Symptom

Deployment passes preflight PHP version checks but results in 502 Bad Gateway errors. Caddy logs show:

```
dial unix /home/orbit/.config/orbit/php/php8.4.sock: connect: no such file or directory
```

The actual socket file exists at:
```bash
ls ~/.config/orbit/php/
# php84.sock  (NOT php8.4.sock)
```

## Investigation

### Attempted: Check PHP installation
Result: PHP 8.4 was installed and running, socket was active

### Attempted: Check Caddy configuration
Result: Caddyfile referenced `php_fastcgi unix/.../php8.4.sock`

### Root Cause
PHP socket naming convention uses **version without dots**: `php84.sock`, `php85.sock`, `php83.sock`

But deployment code was checking/generating paths with dots: `php8.4.sock`

## Root Cause

### Socket Naming Convention
Orbit's PHP-FPM sockets use concatenated version numbers without separators:

```bash
~/.config/orbit/php/
├── php83.sock      # PHP 8.3
├── php84.sock      # PHP 8.4
└── php85.sock      # PHP 8.5
```

**NOT**:
```bash
# ❌ Wrong format (these don't exist)
├── php8.3.sock
├── php8.4.sock
└── php8.5.sock
```

### Preflight Check Mismatch

**Before (broken)**:
```php
// GatewayDeployTool.php - Line 216
$socket = "~/.config/orbit/php/php{$phpVersion}.sock";  // php8.4.sock ❌
$check = app(SshService::class)->execute($node, "[ -S {$socket} ] && echo exists || echo missing");
```

**After (fixed)**:
```php
// Remove dots from version (8.4 -> 84, 8.5 -> 85)
$versionClean = str_replace('.', '', $phpVersion);
$socket = "~/.config/orbit/php/php{$versionClean}.sock";  // php84.sock ✓
```

## Solution

### Preflight Validation

```php
// packages/app/src/Mcp/Tools/Gateway/GatewayDeployTool.php

private function preflight(Node $node, ?string $repo, ?string $phpVersion = null): ?ResponseFactory
{
    // ... other checks ...

    if ($phpVersion) {
        // Strip dots from version string
        $versionClean = str_replace('.', '', $phpVersion);
        $socket = "~/.config/orbit/php/php{$versionClean}.sock";

        $check = app(SshService::class)->execute($node, "[ -S {$socket} ] && echo exists || echo missing");

        if (trim($check['output'] ?? '') === 'missing') {
            // List available versions with proper formatting
            $available = app(SshService::class)->execute(
                $node,
                "ls ~/.config/orbit/php/php*.sock 2>/dev/null | grep -oP 'php\K[0-9]+' | sed 's/\\(.\\)\\(.\\)/\\1.\\2/' | sort -rn | tr '\n' ', ' | sed 's/,$//'"
            );

            return Response::structured([
                'success' => false,
                'error' => "PHP {$phpVersion} not available on node '{$node->name}'. Available versions: " . trim($available['output'] ?: 'none'),
            ]);
        }
    }

    return null;
}
```

### Enhanced Error Message

**Before**:
```
PHP version not found
```

**After**:
```
PHP 8.4 not available on node 'production'. Available versions: 8.5, 8.3
```

## Prevention

### When Working with PHP Versions

```php
// ✓ Correct - strip dots before building socket path
$version = '8.4';
$versionClean = str_replace('.', '', $version);  // "84"
$socket = "php{$versionClean}.sock";  // "php84.sock"

// ✓ Correct - PhpManager handles this internally
$manager = app(PhpManager::class);
$socket = $manager->getSocketPath('8.4');  // Returns path with php84.sock

// ❌ Wrong - using version with dot
$socket = "php{$version}.sock";  // "php8.4.sock" - doesn't exist!
```

### Caddyfile Generation

Always use the socket path helper:

```php
// From CaddyfileGenerator or deployment code
$phpManager = app(\App\Services\PhpManager::class);
$socketPath = $phpManager->getSocketPath($phpVersion);  // Handles dot removal

// Generate Caddy block
$caddy = <<<CADDY
example.com {
    php_fastcgi unix/{$socketPath}
}
CADDY;
```

### Warning Signs

- 502 errors immediately after deployment
- Caddy logs show "no such file or directory" for PHP socket
- Socket check passes but site doesn't work
- Different PHP version works but requested version fails

### Test Case

```php
/** @test */
public function it_validates_php_version_with_correct_socket_format(): void
{
    // Mock node with PHP 8.4 installed
    $node = Node::factory()->create();

    // Should check php84.sock, not php8.4.sock
    $this->sshService
        ->shouldReceive('execute')
        ->with($node, '[ -S ~/.config/orbit/php/php84.sock ] && echo exists || echo missing')
        ->andReturn(['success' => true, 'output' => 'exists']);

    $result = $this->tool->preflight($node, 'org/repo', '8.4');

    $this->assertNull($result);  // Validation passes
}
```

## Related

- **PhpManager**: `packages/cli/app/Services/PhpManager.php` - Handles socket paths
- **CaddyfileGenerator**: Uses PhpManager for correct socket paths
- **Deployment Services**: Must normalize version strings before socket checks

## Convention

**Socket Path Format**: `~/.config/orbit/php/php{MAJOR}{MINOR}.sock`

| PHP Version | Socket Filename | ✓/✗ |
|-------------|-----------------|-----|
| 8.3 | `php83.sock` | ✓ |
| 8.4 | `php84.sock` | ✓ |
| 8.5 | `php85.sock` | ✓ |
| 8.4 | `php8.4.sock` | ✗ |

**Always strip dots from version strings when building socket paths.**
