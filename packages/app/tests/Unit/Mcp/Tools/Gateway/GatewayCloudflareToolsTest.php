<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Services\CloudflareService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Setting::set('cloudflare_api_token', 'test-token');
    Setting::set('cloudflare_zone_id', 'zone-abc');
});

describe('GatewayCloudflareStatusTool', function () {
    it('returns zone info when configured', function () {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-abc' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'zone-abc',
                    'name' => 'example.com',
                    'status' => 'active',
                    'name_servers' => ['ns1.cloudflare.com', 'ns2.cloudflare.com'],
                ],
            ]),
        ]);

        $service = new CloudflareService;
        $zone = $service->getZone();

        expect($zone['name'])->toBe('example.com');
        expect($zone['status'])->toBe('active');
    });

    it('reports not configured when credentials missing', function () {
        Setting::set('cloudflare_api_token', null);

        $service = new CloudflareService;

        expect($service->isConfigured())->toBeFalse();
    });
});

describe('GatewayCloudflareDnsTool', function () {
    it('lists dns records', function () {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-abc/dns_records*' => Http::response([
                'success' => true,
                'result' => [
                    [
                        'id' => 'rec-1',
                        'name' => 'app.example.com',
                        'type' => 'A',
                        'content' => '1.2.3.4',
                        'proxied' => true,
                        'ttl' => 1,
                    ],
                ],
            ]),
        ]);

        $service = new CloudflareService;
        $records = $service->listRecords();

        expect($records)->toHaveCount(1);
        expect($records[0]['name'])->toBe('app.example.com');
    });

    it('filters by record type', function () {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [],
            ]),
        ]);

        $service = new CloudflareService;
        $service->listRecords(type: 'CNAME');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'type=CNAME');
        });
    });
});

describe('GatewayCloudflareAddRecordTool', function () {
    it('creates an A record', function () {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-abc/dns_records' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'new-record',
                    'name' => 'new.example.com',
                    'type' => 'A',
                    'content' => '5.6.7.8',
                    'proxied' => true,
                ],
            ]),
        ]);

        $service = new CloudflareService;
        $record = $service->createRecord('new.example.com', '5.6.7.8');

        expect($record['id'])->toBe('new-record');
        expect($record['name'])->toBe('new.example.com');
    });

    it('creates a CNAME record with proxied false', function () {
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'cname-rec',
                    'name' => 'alias.example.com',
                    'type' => 'CNAME',
                    'content' => 'target.example.com',
                    'proxied' => false,
                ],
            ]),
        ]);

        $service = new CloudflareService;
        $record = $service->createRecord('alias.example.com', 'target.example.com', 'CNAME', false);

        expect($record['type'])->toBe('CNAME');
        expect($record['proxied'])->toBeFalse();
    });
});

describe('GatewayCloudflareRemoveRecordTool', function () {
    it('deletes a record by id', function () {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-abc/dns_records/rec-to-delete' => Http::response([
                'success' => true,
                'result' => ['id' => 'rec-to-delete'],
            ]),
        ]);

        $service = new CloudflareService;
        $result = $service->deleteRecord('rec-to-delete');

        expect($result)->toBeTrue();
    });
});
