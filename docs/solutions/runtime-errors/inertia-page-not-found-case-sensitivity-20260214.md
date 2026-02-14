---
date: 2026-02-14
problem_type: runtime
component: inertia/vite
severity: critical
symptoms:
  - "[Inertia] Page not found: home.vue"
  - "Cannot read properties of undefined (reading 'default')"
root_cause: macOS case-insensitive filesystem masks filename case mismatches that break on Linux
tags: [inertia, vite, case-sensitivity, macos, linux, deployment]
---

# Inertia Page Not Found Due to Case-Sensitive Filesystem on Linux

## Symptom

After deploying to a Linux production server, the browser console shows:

```
[Inertia] Page not found: home.vue
Uncaught (in promise) TypeError: Cannot read properties of undefined (reading 'default')
```

The same code works perfectly on macOS (local development).

## Investigation

1. Checked controller: `inertia('home', [...])` — lowercase
2. Checked file locally: `ls` shows `home.vue` (lowercase) — correct
3. Checked file on production: `ls` shows `Home.vue` (uppercase) — mismatch!
4. Vite's `import.meta.glob` builds keys like `/resources/js/pages/Home.vue` (matching the actual filename on disk), but Inertia resolves with `/resources/js/pages/home.vue` (from the controller)

## Root Cause

The file was originally `Home.vue` from the craft-starterkit. During development on macOS, the file was renamed to `home.vue` to match the controller's `inertia('home')` call. However, **macOS's case-insensitive filesystem (APFS)** treats `Home.vue` and `home.vue` as the same file. Git doesn't detect the rename because the filesystem reports no change.

On Linux (case-sensitive ext4), `Home.vue` and `home.vue` are different files. The Vite glob finds `Home.vue` (the actual file), but the lookup key from the controller is `home.vue` — no match.

## Solution

Force git to track the case change with a two-step rename:

```bash
git mv resources/js/pages/Home.vue resources/js/pages/home-temp.vue
git mv resources/js/pages/home-temp.vue resources/js/pages/home.vue
git commit -m "fix: rename Home.vue to home.vue for case-sensitive Linux filesystems"
```

Then on production: `git pull && bun run build`

## Prevention

- **Convention**: Inertia page filenames must exactly match the string passed to `inertia()`. If the controller uses `inertia('home')`, the file must be `home.vue` (not `Home.vue`).
- **Before deploying to Linux**: Run `git ls-files resources/js/pages/` and verify filenames match controller references.
- **Use `git mv` for renames on macOS**: Direct `mv` won't be tracked by git on case-insensitive filesystems.
- Consider adding a CI check that verifies Inertia page name references match actual filenames.

## Related

- craft-laravel `initializeCraft()` page resolver in `@hardimpactdev/craft-ui/dist/vite/craftPlugin.js`
- Vite `import.meta.glob('/resources/js/pages/**/*.vue')` — case-sensitive key matching
