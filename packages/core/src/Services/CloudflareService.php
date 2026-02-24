<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareService
{
    protected const string BASE_URL = 'https://api.cloudflare.com/client/v4';

    protected ?string $apiToken = null;

    protected ?string $zoneId = null;

    protected bool $loaded = false;

    private array $zoneCache = [];

    public static function listZonesWithToken(string $apiToken): array
    {
        $response = Http::withToken($apiToken)
            ->acceptJson()
            ->withOptions(['force_ip_resolve' => 'v4'])
            ->get(self::BASE_URL.'/zones');

        $data = $response->json() ?? [];

        return $data['result'] ?? [];
    }

    public function isConfigured(?string $zoneId = null): bool
    {
        $this->loadCredentials();

        if ($this->apiToken === null) {
            return false;
        }

        return $zoneId !== null || $this->zoneId !== null;
    }

    public function detectZoneForDomain(string $domain): ?array
    {
        $this->loadCredentials();

        if ($this->apiToken === null) {
            return null;
        }

        $zones = $this->getCachedZones();

        $bestMatch = null;
        $bestLength = 0;

        foreach ($zones as $zone) {
            $zoneName = $zone['name'] ?? '';
            if ($zoneName === $domain || str_ends_with($domain, '.'.$zoneName)) {
                if (strlen($zoneName) > $bestLength) {
                    $bestMatch = [
                        'zone_id' => $zone['id'],
                        'zone_name' => $zoneName,
                    ];
                    $bestLength = strlen($zoneName);
                }
            }
        }

        return $bestMatch;
    }

    public function listZones(): array
    {
        $this->loadCredentials();

        if ($this->apiToken === null) {
            return [];
        }

        return $this->getCachedZones();
    }

    private function getCachedZones(): array
    {
        if ($this->zoneCache === []) {
            $this->zoneCache = self::listZonesWithToken($this->apiToken);
        }

        return $this->zoneCache;
    }

    protected function loadCredentials(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->apiToken = Setting::get('cloudflare_api_token');
        $this->zoneId = Setting::get('cloudflare_zone_id');
        $this->loaded = true;
    }

    protected function resolveZoneId(?string $zoneId = null): string
    {
        $resolved = $zoneId ?? $this->zoneId;

        if ($resolved === null) {
            $this->loadCredentials();
            $resolved = $this->zoneId;
        }

        if ($resolved === null) {
            throw new \RuntimeException('Cloudflare zone ID not configured');
        }

        return $resolved;
    }

    protected function zonePath(string $suffix = '', ?string $zoneId = null): string
    {
        $resolved = $this->resolveZoneId($zoneId);

        return "/zones/{$resolved}{$suffix}";
    }

    public function createRecord(string $name, string $content, string $type = 'A', bool $proxied = true, ?string $zoneId = null): ?array
    {
        $response = $this->request('POST', $this->zonePath('/dns_records', $zoneId), [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'proxied' => $proxied,
        ]);

        return $response['result'] ?? null;
    }

    public function deleteRecord(string $recordId, ?string $zoneId = null): bool
    {
        $response = $this->request('DELETE', $this->zonePath("/dns_records/{$recordId}", $zoneId));

        return $response['success'] ?? false;
    }

    public function updateRecord(string $recordId, array $data, ?string $zoneId = null): ?array
    {
        $response = $this->request('PATCH', $this->zonePath("/dns_records/{$recordId}", $zoneId), $data);

        return $response['result'] ?? null;
    }

    public function listRecords(?string $name = null, ?string $type = null, ?string $zoneId = null): array
    {
        $query = [];
        if ($name) {
            $query['name'] = $name;
        }
        if ($type) {
            $query['type'] = $type;
        }

        $response = $this->request('GET', $this->zonePath('/dns_records', $zoneId), $query);

        return $response['result'] ?? [];
    }

    public function isDomainAvailable(string $fqdn, ?string $zoneId = null): bool
    {
        $records = $this->listRecords(name: $fqdn, zoneId: $zoneId);

        return count($records) === 0;
    }

    public function getZone(?string $zoneId = null): ?array
    {
        $response = $this->request('GET', $this->zonePath(zoneId: $zoneId));

        return $response['result'] ?? null;
    }

    public function getDnssec(string $zoneId): ?array
    {
        $response = $this->request('GET', "/zones/{$zoneId}/dnssec");

        return $response['result'] ?? null;
    }

    public function enableDnssec(string $zoneId): ?array
    {
        $response = $this->request('PATCH', "/zones/{$zoneId}/dnssec", [
            'status' => 'active',
        ]);

        return $response['result'] ?? null;
    }

    public function setSslMode(string $zoneId, string $mode): bool
    {
        try {
            $response = $this->request('PATCH', $this->zonePath('/settings/ssl', $zoneId), [
                'value' => $mode,
            ]);

            Log::info('Set Cloudflare SSL mode', [
                'zone_id' => $zoneId,
                'mode' => $mode,
            ]);

            return $response['success'] ?? false;
        } catch (\Throwable $e) {
            Log::error("Failed to set Cloudflare SSL mode: {$e->getMessage()}", [
                'zone_id' => $zoneId,
                'mode' => $mode,
            ]);

            return false;
        }
    }

    public function purgeCache(?string $zoneId = null): bool
    {
        try {
            $response = $this->request('POST', $this->zonePath('/purge_cache', $zoneId), [
                'purge_everything' => true,
            ]);

            Log::info('Purged Cloudflare cache', [
                'zone_id' => $this->resolveZoneId($zoneId),
            ]);

            return $response['success'] ?? false;
        } catch (\Throwable $e) {
            Log::error("Failed to purge Cloudflare cache: {$e->getMessage()}", [
                'zone_id' => $zoneId,
            ]);

            return false;
        }
    }

    public function purgeCacheByUrls(array $urls, ?string $zoneId = null): bool
    {
        try {
            $response = $this->request('POST', $this->zonePath('/purge_cache', $zoneId), [
                'files' => $urls,
            ]);

            Log::info('Purged Cloudflare cache by URLs', [
                'zone_id' => $this->resolveZoneId($zoneId),
                'url_count' => count($urls),
            ]);

            return $response['success'] ?? false;
        } catch (\Throwable $e) {
            Log::error("Failed to purge Cloudflare cache by URLs: {$e->getMessage()}", [
                'zone_id' => $zoneId,
            ]);

            return false;
        }
    }

    /**
     * Create a "Cache Everything" cache rule for a zone.
     * Idempotent — overwrites any existing cache rules in the zone.
     */
    public function createCacheRule(?string $zoneId = null): bool
    {
        try {
            $resolved = $this->resolveZoneId($zoneId);

            $response = $this->request('PUT', "/zones/{$resolved}/rulesets/phases/http_request_cache_settings/entrypoint", [
                'rules' => [[
                    'expression' => 'true',
                    'description' => 'Cache everything - respect origin Cache-Control',
                    'action' => 'set_cache_settings',
                    'action_parameters' => [
                        'cache' => true,
                        'browser_ttl' => ['mode' => 'respect_origin'],
                    ],
                ]],
            ]);

            if ($response['success'] ?? false) {
                Log::info('Created Cloudflare cache rule', ['zone_id' => $resolved]);

                return true;
            }

            Log::warning('Failed to create Cloudflare cache rule', [
                'zone_id' => $resolved,
                'errors' => $response['errors'] ?? [],
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error("Failed to create Cloudflare cache rule: {$e->getMessage()}", [
                'zone_id' => $zoneId,
            ]);

            return false;
        }
    }

    protected function request(string $method, string $path, array $data = []): array
    {
        $this->loadCredentials();

        $url = self::BASE_URL.$path;

        $request = Http::withToken($this->apiToken)
            ->acceptJson()
            ->withOptions(['force_ip_resolve' => 'v4']);

        $response = match ($method) {
            'GET' => $request->get($url, $data),
            'POST' => $request->post($url, $data),
            'PATCH' => $request->patch($url, $data),
            'PUT' => $request->put($url, $data),
            'DELETE' => $request->delete($url),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        $json = $response->json() ?? [];

        if (! $response->successful()) {
            $errors = $json['errors'] ?? [];
            $message = ! empty($errors) ? ($errors[0]['message'] ?? 'Unknown error') : "HTTP {$response->status()}";
            Log::warning("Cloudflare API error [{$method} {$path}]: {$message}");

            return array_merge($json, ['success' => false]);
        }

        return $json;
    }
}
