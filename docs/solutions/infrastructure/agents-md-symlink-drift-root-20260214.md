---
date: 2026-02-14
problem_type: configuration drift
component: root AGENTS.md / CLAUDE.md
severity: minor
symptoms:
  - "AGENTS.md is 12KB (stale from Feb 8), CLAUDE.md is 42KB (current)"
  - "Root CLAUDE.md and AGENTS.md are separate files, not symlinked"
root_cause: Root directory never had symlink set up; package-level symlinks were correct
tags: [symlink, agents-md, documentation]
---

# Root AGENTS.md / CLAUDE.md Symlink Not Set Up

## Symptom

After updating `CLAUDE.md` with new documentation (Models section, GatewayServer tools), `AGENTS.md` at the root was stale (12KB vs 42KB). The two files were independent — no symlink.

Package-level symlinks were fine:
- `packages/app/CLAUDE.md → AGENTS.md` (correct)
- `packages/core/CLAUDE.md → AGENTS.md` (correct)

## Root Cause

The root directory never had the symlink established. Both files existed independently.

## Solution

```bash
cp CLAUDE.md AGENTS.md && rm CLAUDE.md && ln -s AGENTS.md CLAUDE.md
```

Result: `CLAUDE.md → AGENTS.md` (AGENTS.md is the source of truth)

## Prevention

- After creating any new `CLAUDE.md`, always verify symlink: `ls -la CLAUDE.md AGENTS.md`
- Convention: `AGENTS.md` is the canonical file, `CLAUDE.md` symlinks to it
- When editing, use either path — both resolve to the same file via symlink
