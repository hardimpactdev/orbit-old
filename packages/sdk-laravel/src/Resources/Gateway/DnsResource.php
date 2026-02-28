<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources\Gateway;

use HardImpact\Orbit\Data\Gateway\DnsMapping;
use HardImpact\Orbit\Requests\Gateway\Dns\AddTld;
use HardImpact\Orbit\Requests\Gateway\Dns\ListDnsMappings;
use HardImpact\Orbit\Requests\Gateway\Dns\RemoveTld;

class DnsResource extends GatewayResource
{
    /**
     * @return DnsMapping[]
     */
    public function list(): array
    {
        $response = $this->connector->send(new ListDnsMappings);
        $data = $this->unwrap($response);

        return array_map(
            fn (array $mapping) => DnsMapping::from([
                'tld' => $mapping['tld'],
                'ip' => $mapping['ip'],
            ]),
            $data['mappings'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function addTld(string $tld, string $ip): array
    {
        $response = $this->connector->send(new AddTld($tld, $ip));

        return $this->unwrap($response);
    }

    /** @return array<string, mixed> */
    public function removeTld(string $tld): array
    {
        $response = $this->connector->send(new RemoveTld($tld));

        return $this->unwrap($response);
    }
}
