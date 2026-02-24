<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\RemoteDeploy;

use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\SshService;

final class RemoteCaddyManager
{
    private const string TEMPLATE = <<<'CADDY'
ORBIT_DOMAIN {
    tls {
        issuer acme {
            dns cloudflare {env.CLOUDFLARE_API_TOKEN}
        }
    }
    root * ORBIT_ROOT_PATH
    encode gzip

    @static {
        path /build/*
    }
    header @static Cache-Control "public, max-age=31536000, immutable"

    header {
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
        X-XSS-Protection "1; mode=block"
        Referrer-Policy "strict-origin-when-cross-origin"
        Permissions-Policy "camera=(), microphone=(), geolocation=()"
        Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
        -Server
    }

    @blocked path /.env /.env.* /.git/* /vendor/* /storage/* /config/* /database/* /node_modules/* /.htaccess /composer.json /composer.lock /package.json /package-lock.json /bun.lock* /vite.config.* /artisan
    respond @blocked 404

    handle /.well-known/security.txt {
        header Content-Type "text/plain"
        respond <<TXT
            Contact: mailto:nick@platform11.nl
            Expires: 2027-02-16T00:00:00.000Z
            Preferred-Languages: en, nl
            TXT 200
    }

    php_fastcgi unix/ORBIT_SOCKET_PATH
    file_server
}
CADDY;

    public function __construct(
        protected SshService $ssh,
    ) {}

    /**
     * Write a Caddy site block for the project and reload Caddy.
     */
    public function configure(RemoteDeployContext $ctx, bool $force = false): array
    {
        $domain = $ctx->domain();
        if (! $domain) {
            return ['success' => false, 'error' => 'No domain available for Caddy configuration'];
        }

        $sitesDir = '~/.config/orbit/caddy/sites';
        $caddyFile = "{$sitesDir}/{$ctx->slug}.caddy";

        // Skip if already exists (unless force)
        if (! $force && $this->ssh->fileExists($ctx->node, $caddyFile)) {
            return ['success' => true, 'skipped' => true, 'message' => 'Caddy block already exists'];
        }

        // Ensure sites directory exists
        $this->ssh->execute($ctx->node, "mkdir -p {$sitesDir}");

        // Backup existing file before overwriting
        if ($force && $this->ssh->fileExists($ctx->node, $caddyFile)) {
            $this->ssh->execute($ctx->node, "cp {$caddyFile} {$caddyFile}.bak");
        }

        // Generate the Caddy block
        $block = str_replace(
            ['ORBIT_DOMAIN', 'ORBIT_ROOT_PATH', 'ORBIT_SOCKET_PATH'],
            [$domain, "{$ctx->basePath}/current/public", $ctx->socketPath()],
            self::TEMPLATE
        );

        // Write via base64 transfer
        $result = $this->ssh->writeFile($ctx->node, $caddyFile, $block);
        if (! $result['success']) {
            return ['success' => false, 'error' => trim($result['error'] ?? '') ?: 'Failed to write Caddy block'];
        }

        return ['success' => true, 'skipped' => false, 'message' => "Caddy block written: {$caddyFile}"];
    }

    /**
     * Update an existing .caddy file with the current template.
     *
     * Reads the existing file to extract domain, root, and socket, then regenerates
     * from the current TEMPLATE. Returns the diff for preview (dry_run) or writes the file.
     */
    public function update(Node $node, string $slug, bool $dryRun = true): array
    {
        $sitesDir = '~/.config/orbit/caddy/sites';
        $caddyFile = "{$sitesDir}/{$slug}.caddy";

        if (! $this->ssh->fileExists($node, $caddyFile)) {
            return ['success' => false, 'error' => "No .caddy file found for '{$slug}'"];
        }

        // Read existing file to extract domain, root, and socket
        $existing = $this->ssh->execute($node, "cat {$caddyFile}");
        if (! $existing['success']) {
            return ['success' => false, 'error' => 'Failed to read existing .caddy file'];
        }

        $content = $existing['output'] ?? '';

        // Extract domain (first line before opening brace)
        if (! preg_match('/^(\S+)\s*\{/m', $content, $domainMatch)) {
            return ['success' => false, 'error' => 'Could not parse domain from existing .caddy file'];
        }
        $domain = $domainMatch[1];

        // Extract root path
        if (! preg_match('/root\s+\*\s+(\S+)/', $content, $rootMatch)) {
            return ['success' => false, 'error' => 'Could not parse root path from existing .caddy file'];
        }
        $rootPath = $rootMatch[1];

        // Extract socket path
        if (! preg_match('/php_fastcgi\s+unix\/(\S+)/', $content, $socketMatch)) {
            return ['success' => false, 'error' => 'Could not parse socket path from existing .caddy file'];
        }
        $socketPath = $socketMatch[1];

        // Generate new block from current template
        $newBlock = str_replace(
            ['ORBIT_DOMAIN', 'ORBIT_ROOT_PATH', 'ORBIT_SOCKET_PATH'],
            [$domain, $rootPath, $socketPath],
            self::TEMPLATE
        );

        if (trim($content) === trim($newBlock)) {
            return ['success' => true, 'skipped' => true, 'message' => 'Already up to date'];
        }

        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'slug' => $slug,
                'domain' => $domain,
                'changes' => 'Template has been updated with new security headers/rules',
            ];
        }

        // Backup existing file
        $this->ssh->execute($node, "cp {$caddyFile} {$caddyFile}.bak");

        // Write new block
        $result = $this->ssh->writeFile($node, $caddyFile, $newBlock);
        if (! $result['success']) {
            return ['success' => false, 'error' => trim($result['error'] ?? '') ?: 'Failed to write updated Caddy block'];
        }

        return ['success' => true, 'slug' => $slug, 'domain' => $domain, 'message' => 'Updated with current template (backup at .bak)'];
    }

    /**
     * List all .caddy files on a node.
     *
     * @return array{success: bool, files?: string[], error?: string}
     */
    public function listSites(Node $node): array
    {
        $result = $this->ssh->execute($node, 'ls ~/.config/orbit/caddy/sites/*.caddy 2>/dev/null || true');
        if (! $result['success']) {
            return ['success' => false, 'error' => trim($result['error'] ?? '') ?: 'Failed to list .caddy files'];
        }

        $files = array_filter(array_map('trim', explode("\n", $result['output'] ?? '')));
        $slugs = array_map(fn (string $f) => basename($f, '.caddy'), $files);

        return ['success' => true, 'slugs' => array_values($slugs)];
    }

    /**
     * Reload Caddy on a remote node (without requiring a full RemoteDeployContext).
     */
    public function reloadOnNode(Node $node): array
    {
        return $this->ssh->execute($node, 'sudo systemctl reload caddy 2>&1 || true', 10);
    }

    /**
     * Reload Caddy on the remote node.
     */
    public function reload(RemoteDeployContext $ctx): array
    {
        return $this->ssh->execute($ctx->node, 'sudo systemctl reload caddy 2>&1 || true', 10);
    }

    /**
     * Remove a Caddy site block and reload.
     */
    public function remove(RemoteDeployContext $ctx): array
    {
        $caddyFile = "~/.config/orbit/caddy/sites/{$ctx->slug}.caddy";

        $result = $this->ssh->execute($ctx->node, 'rm -f ' . escapeshellarg($caddyFile));
        if (! $result['success']) {
            return $result;
        }

        return $this->reload($ctx);
    }
}
