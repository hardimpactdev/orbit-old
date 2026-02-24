<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;

class CaddyfileGenerator
{
    protected string $caddyfilePath;

    public function __construct(
        protected ConfigManager $configManager,
        protected ProjectScanner $projectScanner,
        protected PhpManager $phpManager,
        protected WorktreeService $worktreeService
    ) {
        $this->caddyfilePath = $this->configManager->getConfigPath().'/caddy/Caddyfile';
    }

    /**
     * Get the PHP-FPM socket path for a version.
     */
    protected function getSocketPath(string $version): string
    {
        return $this->phpManager->getSocketPath($version);
    }

    public function generate(): void
    {
        $this->generateCaddyfile();
    }

    protected function generateCaddyfile(): void
    {
        $projects = $this->projectScanner->scanProjects();
        $defaultPhp = $this->configManager->getDefaultPhpVersion();
        $tld = $this->configManager->get('tld') ?: 'test';
        $defaultSocket = $this->getSocketPath($defaultPhp);

        $caddyfile = '{
    local_certs
    pki {
        ca local {
            intermediate_lifetime 3599d
        }
    }
}

(security_headers) {
    header {
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
        X-XSS-Protection "1; mode=block"
        Referrer-Policy "strict-origin-when-cross-origin"
        Permissions-Policy "camera=(), microphone=(), geolocation=()"
        -Server
    }
}

(security_headers_production) {
    import security_headers
    header {
        Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
    }
}

(path_blocking) {
    @blocked path /.env /.env.* /.git/* /vendor/* /storage/* /config/* /database/* /node_modules/* /.htaccess /composer.json /composer.lock /package.json /package-lock.json /bun.lock* /vite.config.* /artisan
    respond @blocked 404
}

(security_txt) {
    handle /.well-known/security.txt {
        header Content-Type "text/plain"
        respond <<TXT
            Contact: mailto:nick@platform11.nl
            Expires: 2027-02-16T00:00:00.000Z
            Preferred-Languages: en, nl
            TXT 200
    }
}

(cache_headers) {
    @static {
        path /build/*
    }
    header @static Cache-Control "public, max-age=31536000, immutable"
}

';

        // Add orbit management UI site
        $webAppPath = $this->configManager->getWebAppPath();
        if (is_dir($webAppPath)) {
            $caddyfile .= "orbit.{$tld} {
    tls {
        issuer internal {
            lifetime 3598d
        }
    }
    root * {$webAppPath}/public
    encode gzip
    import security_headers
    import path_blocking
    import security_txt
    import cache_headers
    php_fastcgi unix/{$defaultSocket}
    file_server
}

";
        }

        // Generate entry for each project (skip orbit itself - it has its own entry above)
        $orbitDomain = "orbit.{$tld}";
        foreach ($projects as $project) {
            // Skip if this project would conflict with the orbit UI domain
            if (($project['domain'] ?? null) === $orbitDomain) {
                continue;
            }

            $socket = $project['has_custom_php']
                ? $this->getSocketPath($project['php_version'])
                : $defaultSocket;

            // Detect release-based projects (current symlink)
            $currentPath = $project['path'].'/current';
            $root = is_link($currentPath)
                ? $currentPath.'/public'
                : $project['path'].'/public';

            $caddyfile .= "{$project['domain']} {
    tls {
        issuer internal {
            lifetime 3598d
        }
    }
    root * {$root}
    encode gzip

    # Vite dev server proxy (header_up bypasses Vite allowedHosts bug)
    @vite path /@vite/* /@id/* /@fs/* /resources/* /node_modules/* /lang/* /__devtools__/*
    reverse_proxy @vite localhost:5173 {
        header_up Host localhost
    }

    @ws {
        header Connection *Upgrade*
        header Upgrade websocket
    }
    reverse_proxy @ws localhost:5173 {
        header_up Host localhost
    }

    import security_headers
    import path_blocking
    import security_txt
    import cache_headers
    php_fastcgi unix/{$socket}
    file_server
}

";
        }

        // Generate entries for worktrees
        $worktrees = $this->getWorktreesForCaddy();
        foreach ($worktrees as $worktree) {
            $socket = $this->getSocketPath($worktree['php_version']);
            $root = $worktree['path'].'/public';

            $caddyfile .= "{$worktree['domain']} {
    tls {
        issuer internal {
            lifetime 3598d
        }
    }
    root * {$root}
    encode gzip
    import security_headers
    import path_blocking
    import security_txt
    import cache_headers
    php_fastcgi unix/{$socket}
    file_server
}

";
        }

        // Add Reverb WebSocket service if enabled
        // NOTE: ServiceManager is resolved lazily to avoid early file reads during DI resolution
        if (app(ServiceManager::class)->isEnabled('reverb')) {
            $caddyfile .= "reverb.orbit.{$tld} {
    tls {
        issuer internal {
            lifetime 3598d
        }
    }
    import security_headers
    @websocket {
        path /app /app/*
        header Connection *Upgrade*
        header Upgrade websocket
    }
    reverse_proxy @websocket localhost:8080
    reverse_proxy localhost:8080
}

";
        }

        // Import custom site configs from sites/ directory (survives regeneration)
        $sitesDir = $this->configManager->getConfigPath().'/caddy/sites';
        if (is_dir($sitesDir)) {
            $customFiles = glob("{$sitesDir}/*.caddy");
            if ($customFiles) {
                $caddyfile .= "# Custom site configs\n";
                foreach ($customFiles as $file) {
                    $caddyfile .= File::get($file)."\n\n";
                }
            }
        }

        File::put($this->caddyfilePath, $caddyfile);
    }

    public function reload(): bool
    {
        return $this->phpManager->getAdapter()->reloadCaddy();
    }

    public function reloadPhp(): bool
    {
        $defaultVersion = $this->configManager->get('default_php_version', '8.4');

        // Use graceful reload to avoid killing active connections
        return $this->phpManager->getAdapter()->reloadPhpFpm($defaultVersion);
    }

    protected function getWorktreesForCaddy(): array
    {
        try {
            return $this->worktreeService->getLinkedWorktreesForCaddy();
        } catch (\Exception) {
            return [];
        }
    }
}
