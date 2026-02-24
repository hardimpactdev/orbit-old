Now I have a thorough understanding of the codebase. Here's my review.

---

# Critical Review: Orbit Monorepo (cli, core, app)

## 1. Architecture: Solid but Leaky Boundaries

The package split (core/cli/app/desktop) is sound in principle. However, the boundaries are porous in practice:

- **Core depends on `Process` facade** — `ProvisionPipeline`, `GitHubService`, and several provision actions call `Process::run()` directly. This makes "core" not truly portable; it's tightly coupled to being executed locally. The remote orchestrator correctly uses `SshService`, but the local provision pipeline doesn't. This means you can't run provisioning actions remotely through core — only through the CLI.

- **CLI's `ProjectScanner` reaches into core models** — `cleanupOrphanProjects()` at `packages/cli/app/Services/ProjectScanner.php:227` queries `Project::where(...)` directly. If `ProjectScanner` is CLI-specific, this coupling is fine, but it means the scanner can't work without the database, making it hard to test in isolation.

- **Two parallel deployment systems** — `ProvisionPipeline` (core, local/Process-based) and `RemoteDeploymentOrchestrator` (core, SSH-based) duplicate significant logic. The orchestrator rebuilds steps that the pipeline already implements (composer install, node deps, build assets, migrations, optimize). Changes to one must be manually mirrored in the other. This *will* drift over time and likely already has.

**Recommendation:** Extract a shared step definition (e.g., a list of deployment phases) that both the local pipeline and remote orchestrator consume. The "what" shouldn't be duplicated; only the "how" (Process vs SSH) should differ.

## 2. Security: Generally Good, With Gaps

**Strong points:**
- `McpAccessControl` middleware with IP-based subnet filtering
- `escapeshellarg()` used consistently in `DeploymentService` and `SshService`
- `SettingEncryptor` for sensitive values
- The `GatewayCliAdapter` has command character validation

**Concerns:**

- **`RemoteDeploymentOrchestrator` constructs shell commands with string interpolation** — At `RemoteDeploymentOrchestrator.php:81`, `mkdir -p {$ctx->basePath}/...` uses `$ctx->basePath` which derives from `~/projects/{$this->slug}`. The slug comes from user input (project name) and while it's likely slugified, there's no explicit validation that it's shell-safe at the point of use. If someone registers a project with a slug like `foo;rm -rf /`, the path would be `~/projects/foo;rm -rf /`. The slug likely goes through `Str::slug()` upstream, but this is defense-in-depth you're missing.

- **`RemoteDeploymentOrchestrator::ensureCaddyCloudflareToken`** at line 346 writes the Cloudflare API token to a systemd override via `echo | sudo tee`. The token is escaped via `escapeshellarg()` for the echo, but the `$overridePath` at line 337 is a hardcoded constant, which is fine. However, the `grep -oP` check at line 339 to compare existing token has the raw `$token` in its output context — if the token contains regex special characters, this grep could fail silently.

- **`SshService::writeFile`** at line 134 — base64-encodes content and pipes it. The encoded string is *not* wrapped in `escapeshellarg()`: `"echo {$encoded} | base64 -d > ..."`. Base64 output is alphanumeric, so this is safe, but it's a fragile assumption. An explicit `escapeshellarg()` around `$encoded` costs nothing and removes the assumption.

- **`GatewayCliAdapter::sshCommand`** at line 61 — the regex allowlist `/[^a-zA-Z0-9\s:_\-\.\/=,]/` is strict but may block legitimate use cases (e.g., quoted arguments). More importantly, the *validated* command is then embedded in a string that's passed to `escapeshellarg()` as a whole, which is correct — but the validation is redundant noise that gives false confidence.

## 3. Error Handling: Inconsistent Patterns

The codebase mixes three error-reporting styles:

1. **Return arrays** (`['success' => false, 'error' => '...']`) — Used everywhere in services
2. **Throw exceptions** — Used in `RemoteDeploymentOrchestrator` steps
3. **Mixed** — `DeploymentService::deploy()` catches RuntimeException from the orchestrator but propagates it in the project flow

This creates confusion:

- `DeploymentService::undeploy()` at line 272: `return $result['success'] ?? true` — if the CLI undeploy command returns malformed JSON, this silently reports success. The `?? true` is dangerous; it should be `?? false`.

- `RemoteDeploymentOrchestrator::step()` at line 259 has a **bug**: `trim($result['error'] ?? '') ?: 'Unknown error'` is inside a string concatenation with `"{$name} failed: "`, but PHP's `?:` has lower precedence than `.` concatenation. This actually works correctly because of how `throw new \RuntimeException(...)` receives the expression, but it's confusing to read and easy to break.

## 4. Hardcoded Values and Magic Strings

- **PHP versions hardcoded**: `ProjectScanner::isValidPhpVersion()` at line 162 hardcodes `['8.3', '8.4', '8.5']`. `GitHubService::getRecommendedPhpVersion()` at line 117 also hardcodes `['8.5', '8.4', '8.3']`. `ConfigurationService::getLocalAvailablePhpVersions()` at line 221 falls back to the same list. When PHP 8.6 releases, you need to find and update all of these.

- **Default PHP version inconsistency**: `ConfigManager::getDefaultPhpVersion()` returns `'8.3'`, but `ProjectCreateCommand` defaults to `'8.4'` at line 83, and `ConfigurationService::getDefaultConfig()` defaults to `'8.4'`. Pick one.

- **Hardcoded email in Caddy templates**: `nick@platform11.nl` appears in both `CaddyfileGenerator` (line 78) and `RemoteCaddyManager::TEMPLATE` (line 43). This should be a configurable setting.

- **Vite dev server port 5173 hardcoded** in `CaddyfileGenerator` at lines 145/153. If a project uses a different port, there's no override.

## 5. Test Coverage: Thin on Critical Paths

Looking at the test files:

- **No integration test for the full deployment flow** — `DeploymentServiceTest` exists but `RemoteDeploymentOrchestrator` has no integration test. The orchestrator is the most complex and most dangerous code path (it runs shell commands on production servers).

- **No test for `SshService::writeFile`** — This is used to write `.env` files and Caddy configs on production. A bug here (e.g., truncated base64) would silently break deployments.

- **No test for `ensureCaddyCloudflareToken`** — This writes to systemd on production servers. If it fails, HTTPS certificates fail.

- **`ProjectScanner` test coverage** — Feature tests exist but given the scanner's central role in Caddy generation (broken scan = broken web server), edge cases like symlinks, missing directories, and race conditions deserve more coverage.

## 6. Operational Risks

### The `~/` Path Problem
`RemoteDeployContext` builds paths using `~/projects/{slug}`. The tilde `~` is expanded by the shell, not by PHP. This means:
- `SshService::directoryExists()` runs `[ -d ~/projects/... ]` — the remote shell expands `~` correctly
- But `SshService::writeFile()` uses `echo ... | base64 -d > ~/projects/...` — also shell-expanded, fine
- However, `SshService::fileExists()` and friends all depend on shell expansion

This works *as long as every SSH command goes through a shell*. If you ever change to an SSH library that doesn't spawn a shell (e.g., phpseclib), every `~/` path breaks silently. Consider using `$HOME/projects/` explicitly.

### Release Cleanup Race Condition
`cleanupReleases()` at line 353 reads the symlink target, then lists and deletes old releases. If two deploys happen simultaneously (e.g., two MCP tool calls), the second deploy could delete the first's release before it finishes switching the symlink. There's no locking mechanism.

### Caddy Reload During Deploy
The first deploy creates a Caddy config and reloads. If Caddy reload fails (e.g., invalid config syntax from a template bug), the deployment reports success but the site is unreachable. The reload result at `RemoteDeploymentOrchestrator::configureCaddy` is captured but not checked for fatality.

## 7. Design Opinions

### `Setting` Model is a God Object
`Setting::get()` and `Setting::set()` with string keys is essentially a key-value store with no type safety. Callers use magic strings like `'cloudflare_api_token'`, `'wg_easy_password'`, `'editor_scheme'`. A typo fails silently. Consider an enum for known setting keys.

### Too Many Singletons
`OrbitCoreServiceProvider` registers 15 singletons. Most of these services hold no state and could be resolved fresh each time. `SshService` is a genuine singleton candidate (it manages connection pooling), but `StatusService`, `PackageService`, etc. are stateless wrappers that don't benefit from singleton registration.

### `DeploymentService` Does Too Much
This single class handles: deploying, undeploying, syncing nodes, querying deployments, and querying nodes. The deploy flow alone manages CLI execution, remote orchestration, DNS cleanup, and state management. This is a transaction script that would benefit from being split into discrete operations (deploy, undeploy, sync as separate services).

## 8. Actionable Priorities

1. **Fix `undeploy()` `?? true` bug** — This silently swallows failures. Change to `?? false`.
2. **Validate slugs at the boundary** — Add a `Slug` value object or assert `preg_match('/^[a-z0-9-]+$/', $slug)` before using it in shell commands.
3. **Centralize PHP version lists** — Single config constant, referenced everywhere.
4. **Add integration tests for `RemoteDeploymentOrchestrator`** — Mock `SshService` and verify the full command sequence.
5. **Replace `~` with `$HOME`** — Defensive against future SSH transport changes.
6. **Add deployment locking** — Advisory lock per node+slug to prevent concurrent deploys.
7. **Unify default PHP version** — Pick 8.4 or 8.5 and use it everywhere.
