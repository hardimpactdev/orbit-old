---
date: 2026-02-15
problem_type: workflow
component: cli/release
severity: moderate
symptoms:
  - "Production running phar reports old version after orbit upgrade"
  - "orbit upgrade not working on manually deployed phar"
root_cause: Phar-based CLI installs can't self-upgrade to static binaries
tags: [cli, release, upgrade, phar, static-binary, production]
---

# CLI Release and Multi-Node Upgrade Workflow

## Context

The orbit CLI is built as a static binary (PHP embedded via phpmicro) and released per-platform. The monorepo `orbit` triggers CI builds on tag push. All nodes must be upgraded after a release.

## Release Flow (Monorepo)

```bash
# 1. Commit changes
git add ... && git commit -m "feat: description"

# 2. Tag and push (triggers CI)
git tag v0.1.XXX
git push origin main --tags

# 3. Wait for "Build and Release CLI" workflow
gh run watch <run-id> --repo hardimpactdev/orbit --exit-status

# 4. Verify release assets exist
gh release view v0.1.XXX --repo hardimpactdev/orbit-cli
```

CI produces: `orbit-linux-x86_64`, `orbit-linux-aarch64`, `orbit-macos-aarch64`

## Upgrade All Nodes

```bash
# Dev server (static binary — orbit upgrade works)
ssh ai "~/.local/bin/orbit upgrade"

# Gateway (static binary — orbit upgrade works)
ssh gateway "~/.local/bin/orbit upgrade"

# Production (verify it's a static binary first)
ssh orbit@46.225.89.66 "~/.local/bin/orbit upgrade"
```

## Gotcha: Phar-Based Install Can't Self-Upgrade

If a node is running a phar (e.g., from manual `scp builds/orbit.phar`), `orbit upgrade` may not work or may report the wrong version. The phar doesn't have the embedded PHP runtime and its version metadata is from the build-time composer.json, not the release tag.

**Fix**: Download the static binary directly:

```bash
ssh orbit@46.225.89.66 'curl -sL \
  https://github.com/hardimpactdev/orbit-cli/releases/download/v0.1.XXX/orbit-linux-x86_64 \
  -o /tmp/orbit-new && chmod +x /tmp/orbit-new && mv /tmp/orbit-new ~/.local/bin/orbit'
```

**How to detect**: If `file ~/.local/bin/orbit` shows "PHP script" instead of "ELF 64-bit", it's a phar.

## SSH Aliases

Use the SSH config aliases, not `user@host` directly:

| Node | Correct | Wrong |
|------|---------|-------|
| Dev | `ssh ai` | `ssh orbit@ai` (may fail if SSH config sets different user) |
| Gateway | `ssh gateway` | `ssh gateway@188.245.156.201` |
| Production | `ssh orbit@46.225.89.66` | (no alias configured) |

## Related

- `docs/reference/cli-development.md` (release workflow reference)
- `docs/solutions/build-errors/phar-build-vendor-symlink-orbit-core-20260215.md` (local phar build)
