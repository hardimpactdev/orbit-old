# Common Tasks

## Adding a new orbit CLI command

1. Add method to `LaunchpadService` that calls `executeCommand($environment, 'command --json')`
2. Add controller method in `EnvironmentController`
3. Add route in `routes/web.php` under the environments prefix group
4. Add frontend component in Vue

## SSH Connection Issues

- Control sockets are stored in `/tmp/orbit-ssh/` with hashed filenames
- PATH is prefixed for non-interactive SSH: `$HOME/.local/bin:$HOME/.bun/bin:/usr/local/bin:/usr/bin:/bin`
- Binary detection checks multiple common installation paths
- Use `sg docker -c "command"` to run Docker commands in new SSH sessions (picks up group membership)

## Provisioning Issues

- **SSH host key conflicts**: Provisioning clears old host keys before connecting
- **Docker network not created**: CLI has a bug where `orbit init` doesn't persist the network. Provisioning creates it manually with `docker network create orbit`
- **PHP-FPM pool configuration**: Pool configs are generated at `~/.config/orbit/php/` with Unix sockets
- **systemd-resolved conflict**: Disabled during provisioning as it uses port 53 which orbit DNS needs
- **Horizon service**: Installed as systemd service on Linux, launchd on macOS

## DNS Resolver and TLD Changes

When a TLD is changed in environment settings, three things happen automatically:

1. **Mac DNS Resolver**: `DnsResolverService` creates/updates `/etc/resolver/{tld}` pointing to the environment's DNS (127.0.0.1 for local, host IP for remote)
2. **Remote DNS Container**: `LaunchpadService::rebuildDns()` rebuilds the dnsmasq container with correct TLD and HOST_IP environment variables
3. **Caddy Config Regeneration**: Launchpad is restarted to regenerate Caddy configuration with new domain names

When an environment is deleted, the resolver file is cleaned up (if no other environments use that TLD).

## Git Worktree Support

The app automatically detects git worktrees created by vibekanban (or manually) and makes them available as subdomains.

**How it works:**

1. Worktrees are stored at `/var/tmp/vibe-kanban/worktrees/{task-id}/{project-name}/`
2. Branches follow the pattern `vk/{task-id}` (e.g., `vk/0d16-update-homepage`)
3. Detection runs via `git worktree list --porcelain` in each site directory
4. Auto-linking creates Caddy routes for each worktree subdomain
5. Subdomain format: `{worktree-name}.{site-name}.{tld}` (e.g., `0d16-update-homepage.platform11-2026.bear`)

**CLI Commands (orbit-cli):**

- `orbit worktrees [site] --json` - List all worktrees
- `orbit worktree:unlink <site> <name> --json` - Remove worktree routing
- `orbit worktree:refresh --json` - Re-scan and auto-link new worktrees

**Storage:**

- Linked worktrees stored in `~/.config/orbit/worktrees.json`
- Caddy config automatically regenerated to include worktree subdomains
- PHP-FPM has direct access to worktree paths on the host filesystem

**UI:**

- Sites with worktrees show a badge with count
- Click the arrow to expand and see worktree subdomains
- Each worktree row has Open, Editor, and Unlink buttons

## Touch ID for sudo

The app uses `expect` to spawn sudo commands in a pseudo-terminal (PTY), which enables Touch ID authentication. This requires `/etc/pam.d/sudo_local` to exist with:

```
auth sufficient pam_tid.so
```

Create it with: `sudo sh -c 'echo "auth sufficient pam_tid.so" > /etc/pam.d/sudo_local'`

The expect script approach is necessary because:

- NativePHP runs in a non-TTY context (no terminal)
- `sudo -S` (stdin password) doesn't trigger Touch ID
- `osascript` with administrator privileges shows a password dialog, not Touch ID
- Only a proper PTY (created by `expect`) triggers `pam_tid.so` correctly

## Host Services

**IMPORTANT:** Caddy runs on the host via systemd, NOT in Docker.

| Service | Type | Management |
|---------|------|------------|
| Caddy | systemd | `sudo systemctl reload caddy` |
| Horizon | systemd | `sudo systemctl restart orbit-horizon` |
| PHP-FPM | systemd | `sudo systemctl restart php8.4-fpm` |
| Reverb | Docker | `docker restart orbit-reverb` |

Caddy config: `~/.config/orbit/caddy/Caddyfile` (imported by `/etc/caddy/Caddyfile`)

## Asset Publishing (orbit-app -> orbit-web)

When making UI changes that you want to see on orbit-web.bear:

1. **If Vite dev server is NOT running**: You must build AND publish
   ```bash
   cd ~/projects/orbit-app
   bun run build

   cd ~/projects/orbit-web
   php artisan vendor:publish --tag=orbit-assets --force
   ```

2. **Why changes might not appear**:
   - orbit-web serves from `public/vendor/orbit/build/`
   - These are COPIED from orbit-app, not symlinked
   - Publishing is required after each build

3. **To verify**: Check the timestamp
   ```bash
   ls -la ~/projects/orbit-web/public/vendor/orbit/
   ```

## Vite Development Server with Caddy HTTPS Proxy

When running the Vite dev server behind Caddy for HTTPS:

1. **Use VITE_APP_URL** (not APP_URL) in package.json:
   ```json
   "dev": "sh -c 'VITE_APP_URL=https://$0 vite'"
   ```

2. **Why this matters**: craft-ui's vite config reads `VITE_APP_URL` to:
   - Configure HMR websocket to connect through proxy (`wss://domain.bear:443`)
   - Set proper origin for CORS and asset URLs
   - Write HTTPS URL to hot file instead of `http://0.0.0.0:5173`

3. **Symptoms of incorrect config**:
   - Browser error: "Mixed Content: page loaded over HTTPS but requested insecure script"
   - HMR not working (changes don't reflect instantly)
   - Hot file contains `http://0.0.0.0:5173` instead of `https://domain.bear`

4. **To verify it's working**:
   ```bash
   cat ~/projects/orbit-app/public/hot  # Should show https://orbit-web.bear
   ```
