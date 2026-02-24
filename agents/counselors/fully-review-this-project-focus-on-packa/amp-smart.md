Now I have enough information for a comprehensive review. Here's my analysis:

---

# Full Project Review: orbit-cli, orbit-core, orbit-app

## Executive Summary

The monorepo has a **solid foundation** but shows signs of **rapid growth without consolidation**. The core/cli/app split is directionally correct, but boundaries are leaking. Key risks: **error handling inconsistency**, **service locator anti-patterns**, **security gaps in CLI argument handling**, and **duplicated logic** across packages.

---

## 1. Package Architecture

### What's Working Well
- Clear separation: core = domain, app = UI/MCP, cli = local dev tooling
- Laravel package conventions followed correctly (service providers, migrations, config publishing)
- Singleton registration for stateless services in [OrbitCoreServiceProvider.php](file:///Users/nckrtl/orbit/packages/core/src/OrbitCoreServiceProvider.php#L27-L56)

### Critical Issues

**1.1 Core contains execution mechanisms it shouldn't**

The `HardImpact\Orbit\Core\Services\OrbitCli\` namespace is a smell. Core should define *what* to do, not *how* to execute it via CLI/SSH.

```
packages/core/src/Services/
├── OrbitCli/               ← These belong in cli or app
│   ├── ConfigurationService.php
│   ├── ProjectCliService.php
│   ├── ServiceControlService.php
│   └── Shared/CommandService.php  ← Executes shell commands
```

**Recommendation:** Extract interfaces for these capabilities and move implementations to cli/app.

**1.2 Service locator anti-pattern in [DeploymentService.php#L168](file:///Users/nckrtl/orbit/packages/core/src/Services/DeploymentService.php#L168)**

```php
$detected = app(GitHubService::class)->detectPhpVersion($project->github_repo);
```

This bypasses dependency injection, makes testing harder, and hides dependencies.

---

## 2. Error Handling (High Risk)

### Dangerous Pattern: `?? true` defaults

[DeploymentService.php#L272](file:///Users/nckrtl/orbit/packages/core/src/Services/DeploymentService.php#L272):
```php
return $result['success'] ?? true;  // Malformed response = "success"!
```

This silently treats any unexpected response as success. Should default to `false`.

### Inconsistent error propagation

Mixed patterns across the codebase:
- Thrown exceptions (Node validation)
- Returned `['success' => false, 'error' => ...]` arrays
- Swallowed exceptions with `Log::warning()` ([cleanupDnsOnFailure](file:///Users/nckrtl/orbit/packages/core/src/Services/DeploymentService.php#L147-L163))

**Recommendation:** Standardize on:
1. Exceptions for programmer/config errors
2. Typed `Result` objects for operational failures
3. Never swallow without tracking (add a `needs_cleanup` flag or operation log)

---

## 3. Cross-Package Duplication

Services that exist in **both** cli and core:

| Service | CLI Location | Core Location | Issue |
|---------|-------------|---------------|-------|
| GitHubService | `cli/app/Services/GitHubService.php` | `core/src/Services/Provision/GitHubService.php` | Unclear which to use |
| ProvisionLogger | `cli/app/Services/ProvisionLogger.php` | `core/src/Services/Provision/ProvisionLogger.php` | Intentional (interface impl) |
| WorktreeService | `cli/app/Services/WorktreeService.php` | `core/src/Services/OrbitCli/WorktreeService.php` | Confusing duplication |

**Recommendation:** Define interfaces in core, implementations in cli/app.

---

## 4. Security Concerns

### 4.1 CLI arguments may leak secrets

[DeploymentService.php#L119](file:///Users/nckrtl/orbit/packages/core/src/Services/DeploymentService.php#L116-L128) builds commands like:
```php
$args[] = '--clone=' . escapeshellarg($repo);
```

If `$repo` contains `https://token@github.com/...`, the token appears in:
- Process list (`ps aux`)
- Logs (CommandService logs full commands)
- Shell history on remote servers

**Fix:** Pass credentials via stdin, env vars, or temp files with 0600 permissions.

### 4.2 MCP tools have admin-level power with minimal access control

[GatewayServer.php](file:///Users/nckrtl/orbit/packages/app/src/Mcp/GatewayServer.php#L114-L139) exposes 23 tools that can:
- Create VPN clients
- Manage Cloudflare DNS (any zone)
- Deploy/undeploy to any node

**Missing:**
- Per-tool authorization policies
- Request audit logging
- Rate limiting
- Input validation (beyond basic schema types)

### 4.3 SSH command injection surface

[SshService.php#L106-L127](file:///Users/nckrtl/orbit/packages/core/src/Services/SshService.php#L106-L127) builds SSH commands via string concatenation:
```php
return "ssh {$options} {$node->user}@{$node->host} {$escapedCommand}";
```

While `escapeshellarg` is used, this pattern is fragile. Consider using Laravel Process with explicit argument arrays.

---

## 5. Testing Gaps

Based on test file inventory, likely undertested:

| Critical Path | Test Coverage |
|---------------|---------------|
| Remote deployment failures (SSH errors, malformed JSON) | Low |
| Deployment state transitions (stuck in `Deploying`) | Unclear |
| Multiple JSON objects in SSH output | Known issue, may lack regression test |
| MCP tool authorization | None visible |
| Cloudflare multi-zone edge cases | Unknown |

**Recommended additions:**
1. Integration tests verifying container resolution per package
2. State machine tests for Deployment status transitions
3. Fuzz tests for SSH JSON parsing

---

## 6. Code Quality Observations

### Good Patterns
- Consistent use of `final` and `readonly` on value objects
- `declare(strict_types=1)` everywhere
- Clean enum usage (DeploymentStatus, NodeType, NodeEnvironment)
- Control socket optimization in [SshService](file:///Users/nckrtl/orbit/packages/core/src/Services/SshService.php#L13-L30)

### Areas for Improvement
- [Node.php](file:///Users/nckrtl/orbit/packages/core/src/Models/Node.php) has 237 lines with mixed concerns (validation, state queries, relationship definitions)
- Heavy use of `array` returns instead of typed DTOs
- Some services (DeploymentService) are doing too much — orchestration + persistence + DNS + error cleanup

---

## 7. Priority Recommendations

### Immediate (Security/Stability)
1. **Fix `?? true` defaults** — Change to `?? false` in undeploy and similar
2. **Stop logging full CLI commands** — Redact arguments or use structured logging
3. **Add MCP access control middleware** — IP whitelist + auth tokens at minimum

### Short-term (1-2 days)
4. **Inject GitHubService** in DeploymentService constructor
5. **Create typed Result/DeploymentResult** objects to replace array returns
6. **Add try/catch around deploy orchestration** to prevent stuck `Deploying` state

### Medium-term (1-2 weeks)
7. **Extract execution interfaces** from core → cli/app implementations
8. **Consolidate duplicate services** (GitHubService, WorktreeService)
9. **Add MCP audit logging and per-tool policies**
10. **Write state transition tests** for Deployment model

---

## 8. Architecture Diagram (Current vs Recommended)

```
CURRENT:
┌─────────────────────────────────────────────────────────┐
│ core                                                    │
│  ├── Models, Enums (clean)                              │
│  ├── Services/OrbitCli/* ← CLI execution in core!       │
│  └── Services/Deployment ← knows about CLI, SSH, CF     │
└─────────────────────────────────────────────────────────┘
        ↓ depends on
┌─────────────────┐     ┌─────────────────┐
│ cli             │     │ app             │
│  └── duplicates │     │  └── MCP tools  │
│      some core  │     │      call core  │
└─────────────────┘     └─────────────────┘

RECOMMENDED:
┌─────────────────────────────────────────────────────────┐
│ core                                                    │
│  ├── Models, Enums                                      │
│  ├── Contracts (DeploymentExecutor, DnsProvider, etc.)  │
│  └── Domain Services (pure orchestration)               │
└─────────────────────────────────────────────────────────┘
        ↓ implements contracts
┌─────────────────┐     ┌─────────────────┐
│ cli             │     │ app             │
│  └── CliDeployExec│   │  └── SshDeployExec
│  └── LocalDns    │     │  └── CloudflareDns
└─────────────────┘     └─────────────────┘
```

---

This review identifies real issues but the codebase is well-structured overall. The highest-impact fix is standardizing error handling — the `?? true` patterns are ticking time bombs.
