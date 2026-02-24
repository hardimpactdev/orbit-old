<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\RemoteDeploy;

use HardImpact\Orbit\Core\Services\SshService;

final class RemoteEnvManager
{
    public function __construct(
        protected SshService $ssh,
    ) {}

    /**
     * Bootstrap a .env file for a first deploy.
     * Reads .env.example from the release, applies production defaults,
     * and writes the result to the shared .env path.
     */
    public function bootstrap(RemoteDeployContext $ctx): array
    {
        $sharedEnv = "{$ctx->basePath}/.env";

        // Skip if .env already exists
        if ($this->ssh->fileExists($ctx->node, $sharedEnv)) {
            return ['success' => true, 'skipped' => true, 'message' => 'Shared .env already exists'];
        }

        // Read .env.example from the release
        $envExample = $this->ssh->readFile($ctx->node, "{$ctx->releasePath}/.env.example");
        $env = $envExample ?? '';

        // Apply production defaults
        $env = $this->setEnvValue($env, 'APP_ENV', 'production');
        $env = $this->setEnvValue($env, 'APP_DEBUG', 'false');
        $env = $this->setEnvValue($env, 'APP_URL', $ctx->appUrl());

        // Generate APP_KEY if missing
        if (! preg_match('/^APP_KEY=base64:.+$/m', $env)) {
            $key = 'base64:' . base64_encode(random_bytes(32));
            $env = $this->setEnvValue($env, 'APP_KEY', $key);
        }

        // Production-optimized defaults (only if missing)
        if ($ctx->node->isProduction()) {
            $env = $this->setEnvValueIfMissing($env, 'CACHE_DRIVER', 'redis');
            $env = $this->setEnvValueIfMissing($env, 'SESSION_DRIVER', 'redis');
            $env = $this->setEnvValueIfMissing($env, 'QUEUE_CONNECTION', 'redis');
            $env = $this->setEnvValueIfMissing($env, 'REDIS_HOST', '127.0.0.1');
            $env = $this->setEnvValueIfMissing($env, 'REDIS_PORT', '6379');
        }

        // Write via base64 transfer
        $result = $this->ssh->writeFile($ctx->node, $sharedEnv, $env);

        if (! $result['success']) {
            return ['success' => false, 'error' => trim($result['error'] ?? '') ?: 'Failed to write .env'];
        }

        return ['success' => true, 'skipped' => false, 'message' => 'Bootstrapped .env with production defaults'];
    }

    private function setEnvValue(string $env, string $key, string $value): string
    {
        if (preg_match("/^{$key}=.*/m", $env)) {
            return preg_replace("/^{$key}=.*/m", "{$key}={$value}", $env);
        }

        return rtrim($env) . "\n{$key}={$value}\n";
    }

    private function setEnvValueIfMissing(string $env, string $key, string $value): string
    {
        if (! preg_match("/^{$key}=.*/m", $env)) {
            return $this->setEnvValue($env, $key, $value);
        }

        return $env;
    }
}
