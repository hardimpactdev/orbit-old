# Plan: Static PHP binary + remove web bundle

## Goal
Replace PHAR-based distribution with a self-contained static binary (PHP 8.4 + phpmicro). Remove bundled web app and `web:install` command to shrink the binary.

## 1. Remove web bundle + web:install command

**Delete files:**
- `packages/cli/app/Commands/WebInstallCommand.php`
- `packages/cli/app/Actions/Install/Shared/InstallWebApp.php`
- `packages/cli/app/Actions/Upgrade/UpdateWebApp.php`
- `packages/cli/stubs/orbit-web-bundle.tar.gz` (33MB)

**Edit files:**
- `packages/cli/app/Commands/UpgradeCommand.php` — remove `UpdateWebApp` dependency, remove web app update step from upgrade flow
- `packages/cli/app/Commands/InitCommand.php` — remove `installWebApp()` method and calls to it
- `packages/cli/app/Services/CaddyfileGenerator.php` — remove orbit.{tld} web UI site block (conditionally skip if web path doesn't exist — already does `is_dir()` check, so this may just work, but clean it up)
- `packages/cli/app/Services/HorizonManager.php` — `getWebAppPath()` references stay (Horizon still needs a working directory for `artisan horizon:work`). Actually these reference the installed web app path on disk — if web app isn't installed, Horizon install already skips. No change needed.
- `packages/cli/app/Commands/Setup/LinuxSetup.php` — `installHorizon` already guards with `is_dir($webPath)`, safe as-is
- `packages/cli/app/Commands/Setup/MacSetup.php` — same, safe as-is
- `packages/cli/box.json` — remove `stubs` finder entry (no more stubs in PHAR... wait, stubs still has config.json, horizon stubs, php-fpm stubs, etc. Keep finder but confirm `notName: *.tar.gz` already excludes the bundle. Actually remove the tar.gz file itself and keep the finder.)

## 2. Add static binary build workflow

**New file:** `.github/workflows/build-cli.yml` (replace existing)

Matrix build for 3 targets:
- `linux-x86_64` on `ubuntu-latest`
- `linux-aarch64` on `ubuntu-24.04-arm`
- `macos-aarch64` on `macos-15`

Steps per target:
1. Checkout monorepo
2. Setup PHP 8.4 (for composer/box)
3. Install CLI deps (no dev, no path repos)
4. Set version from tag
5. Compile PHAR with Box (same as current)
6. Download `spc` for target platform
7. `spc doctor --auto-fix`
8. `spc download --with-php=8.4 --for-extensions=<list> --prefer-pre-built`
9. `spc build <extensions> --build-micro --with-micro-fake-cli`
10. `spc micro:combine builds/orbit.phar -I "memory_limit=512M" --output orbit-<target>`
11. Upload artifact

Extensions list (minimal for Laravel Zero + Guzzle + SQLite + Pusher):
`bcmath,ctype,curl,dom,fileinfo,filter,iconv,mbstring,mbregex,openssl,pcntl,pdo,pdo_sqlite,phar,posix,readline,simplexml,sockets,sqlite3,tokenizer,xml,xmlreader,xmlwriter,zip,zlib,sodium`

Release job: download all artifacts, create release on `hardimpactdev/orbit-cli` with per-platform binaries.

## 3. Update UpgradeCommand for multi-platform binaries

Current `UpgradeCommand` looks for `orbit.phar` in release assets. Need to update it to:
- Detect current OS/arch
- Download `orbit-linux-x86_64`, `orbit-linux-aarch64`, or `orbit-macos-aarch64`
- Replace itself (no longer a PHAR — just a native binary)
- Update `isValidPhar()` check — it won't be a PHAR anymore, it'll be a native binary

## 4. Update release.yml

The existing `release.yml` auto-bumps version and creates tag. The `build-cli.yml` triggers on tag push. The release workflow also builds CLI phar for the desktop app — that still needs to produce a PHAR (desktop bundles CLI differently). Keep PHAR build in release.yml for desktop, but the standalone CLI release uses static binaries.

## 5. Update docs

- `packages/cli/CLAUDE.md` / `AGENTS.md` — remove web bundle references
- `packages/cli/stubs/CLAUDE.md` — remove web app references
- `packages/cli/README.md` — update install instructions
- Root `CLAUDE.md` — update CLI install/update instructions

## Unresolved questions

1. **Desktop app still bundles CLI as PHAR** (`release.yml` copies `builds/orbit.phar` to `packages/desktop/bin/`). Should we keep PHAR for desktop embedding, or should desktop also use the static binary? PHAR is probably fine since desktop already has PHP.
2. **Horizon depends on web app** — with web app removed, should we also remove Horizon commands? Or keep them for when users install web app separately later?
3. **`spc doctor --auto-fix`** may need `brew` on macOS runners and `apt` on Ubuntu runners to install build dependencies. Need to verify the CI runners have what's needed.
4. **UPX compression** — available on Linux only. Use it to shrink Linux binaries? Adds build complexity but saves ~30-50% size.
