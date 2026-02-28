<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources\Gateway;

use HardImpact\Orbit\Data\Gateway\CloudflareRecord;
use HardImpact\Orbit\Data\Gateway\CloudflareZone;
use HardImpact\Orbit\Data\Gateway\CloudflareZoneStatus;
use HardImpact\Orbit\Requests\Gateway\Cloudflare\AddDnsRecord;
use HardImpact\Orbit\Requests\Gateway\Cloudflare\CreateCacheRule;
use HardImpact\Orbit\Requests\Gateway\Cloudflare\FlushCache;
use HardImpact\Orbit\Requests\Gateway\Cloudflare\GetZoneStatus;
use HardImpact\Orbit\Requests\Gateway\Cloudflare\ListDnsRecords;
use HardImpact\Orbit\Requests\Gateway\Cloudflare\ListZones;
use HardImpact\Orbit\Requests\Gateway\Cloudflare\RemoveDnsRecord;
use HardImpact\Orbit\Requests\Gateway\Cloudflare\SetSslMode;

class CloudflareResource extends GatewayResource
{
    /**
     * @return CloudflareZone[]
     */
    public function zones(): array
    {
        $response = $this->connector->send(new ListZones);
        $data = $this->unwrap($response);

        return array_map(
            fn (array $zone) => CloudflareZone::from([
                'id' => $zone['id'],
                'name' => $zone['name'],
                'status' => $zone['status'] ?? null,
            ]),
            $data['zones'] ?? [],
        );
    }

    public function zoneStatus(?string $zoneId = null): CloudflareZoneStatus
    {
        $response = $this->connector->send(new GetZoneStatus($zoneId));
        $data = $this->unwrap($response);
        $zone = $data['zone'];

        return CloudflareZoneStatus::from([
            'id' => $zone['id'],
            'name' => $zone['name'],
            'status' => $zone['status'],
            'nameServers' => $zone['name_servers'] ?? [],
        ]);
    }

    /**
     * @return CloudflareRecord[]
     */
    public function dnsRecords(?string $name = null, ?string $type = null, ?string $zoneId = null): array
    {
        $response = $this->connector->send(new ListDnsRecords($name, $type, $zoneId));
        $data = $this->unwrap($response);

        return array_map(
            fn (array $record) => CloudflareRecord::from([
                'id' => $record['id'],
                'name' => $record['name'],
                'type' => $record['type'],
                'content' => $record['content'],
                'proxied' => $record['proxied'] ?? false,
                'ttl' => $record['ttl'] ?? null,
            ]),
            $data['records'] ?? [],
        );
    }

    public function addDnsRecord(
        string $name,
        string $content,
        string $type = 'A',
        bool $proxied = false,
        ?string $zoneId = null,
    ): CloudflareRecord {
        $response = $this->connector->send(new AddDnsRecord($name, $content, $type, $proxied, $zoneId));
        $data = $this->unwrap($response);
        $record = $data['record'];

        return CloudflareRecord::from([
            'id' => $record['id'],
            'name' => $record['name'],
            'type' => $record['type'],
            'content' => $record['content'],
            'proxied' => $record['proxied'] ?? false,
            'ttl' => $record['ttl'] ?? null,
        ]);
    }

    /** @return array<string, mixed> */
    public function removeDnsRecord(string $recordId, ?string $zoneId = null): array
    {
        $response = $this->connector->send(new RemoveDnsRecord($recordId, $zoneId));

        return $this->unwrap($response);
    }

    /** @return array<string, mixed> */
    public function setSslMode(string $zoneId, string $mode): array
    {
        $response = $this->connector->send(new SetSslMode($zoneId, $mode));

        return $this->unwrap($response);
    }

    /** @return array<string, mixed> */
    public function flushCache(?string $zoneId = null, ?string $projectSlug = null, ?string $urls = null): array
    {
        $response = $this->connector->send(new FlushCache($zoneId, $projectSlug, $urls));

        return $this->unwrap($response);
    }

    /** @return array<string, mixed> */
    public function createCacheRule(?string $zoneId = null, ?string $projectSlug = null): array
    {
        $response = $this->connector->send(new CreateCacheRule($zoneId, $projectSlug));

        return $this->unwrap($response);
    }
}
