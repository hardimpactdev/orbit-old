<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Models\Setting;
use Illuminate\Support\Facades\Http;

class CloudflareService
{
    protected const string BASE_URL = 'https://api.cloudflare.com/client/v4';

    protected ?string $apiToken;

    protected ?string $zoneId;

    public function __construct()
    {
        $this->apiToken = Setting::get('cloudflare_api_token');
        $this->zoneId = Setting::get('cloudflare_zone_id');
    }

    public function isConfigured(): bool
    {
        return $this->apiToken !== null && $this->zoneId !== null;
    }

    public function createRecord(string $name, string $content, string $type = 'A', bool $proxied = true): ?array
    {
        $response = $this->request('POST', "/zones/{$this->zoneId}/dns_records", [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'proxied' => $proxied,
        ]);

        return $response['result'] ?? null;
    }

    public function deleteRecord(string $recordId): bool
    {
        $response = $this->request('DELETE', "/zones/{$this->zoneId}/dns_records/{$recordId}");

        return $response['success'] ?? false;
    }

    public function updateRecord(string $recordId, array $data): ?array
    {
        $response = $this->request('PATCH', "/zones/{$this->zoneId}/dns_records/{$recordId}", $data);

        return $response['result'] ?? null;
    }

    public function listRecords(?string $name = null, ?string $type = null): array
    {
        $query = [];
        if ($name) {
            $query['name'] = $name;
        }
        if ($type) {
            $query['type'] = $type;
        }

        $response = $this->request('GET', "/zones/{$this->zoneId}/dns_records", $query);

        return $response['result'] ?? [];
    }

    public function isDomainAvailable(string $fqdn): bool
    {
        $records = $this->listRecords(name: $fqdn);

        return count($records) === 0;
    }

    public function getZone(): ?array
    {
        $response = $this->request('GET', "/zones/{$this->zoneId}");

        return $response['result'] ?? null;
    }

    public function setSslMode(string $mode): bool
    {
        $response = $this->request('PATCH', "/zones/{$this->zoneId}/settings/ssl", [
            'value' => $mode,
        ]);

        return $response['success'] ?? false;
    }

    protected function request(string $method, string $path, array $data = []): array
    {
        $url = self::BASE_URL.$path;

        $request = Http::withToken($this->apiToken)
            ->acceptJson();

        $response = match ($method) {
            'GET' => $request->get($url, $data),
            'POST' => $request->post($url, $data),
            'PATCH' => $request->patch($url, $data),
            'PUT' => $request->put($url, $data),
            'DELETE' => $request->delete($url),
        };

        return $response->json() ?? [];
    }
}
