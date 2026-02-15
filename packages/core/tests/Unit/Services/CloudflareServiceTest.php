<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Services\CloudflareService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Setting::set('cloudflare_api_token', 'test-token');
    Setting::set('cloudflare_zone_id', 'zone-123');
});

describe('CloudflareService', function () {
    describe('isConfigured', function () {
        it('returns true when both token and zone are set', function () {
            $service = new CloudflareService;

            expect($service->isConfigured())->toBeTrue();
        });

        it('returns false when token is missing', function () {
            Setting::set('cloudflare_api_token', null);

            $service = new CloudflareService;

            expect($service->isConfigured())->toBeFalse();
        });

        it('returns false when zone is missing', function () {
            Setting::set('cloudflare_zone_id', null);

            $service = new CloudflareService;

            expect($service->isConfigured())->toBeFalse();
        });
    });

    describe('createRecord', function () {
        it('sends POST request to cloudflare api', function () {
            Http::fake([
                'api.cloudflare.com/client/v4/zones/zone-123/dns_records' => Http::response([
                    'success' => true,
                    'result' => [
                        'id' => 'record-1',
                        'name' => 'app.example.com',
                        'type' => 'A',
                        'content' => '1.2.3.4',
                    ],
                ]),
            ]);

            $service = new CloudflareService;
            $result = $service->createRecord('app.example.com', '1.2.3.4');

            expect($result)->not->toBeNull();
            expect($result['id'])->toBe('record-1');
            expect($result['name'])->toBe('app.example.com');

            Http::assertSentCount(1);
        });

        it('returns null on failure', function () {
            Http::fake([
                'api.cloudflare.com/*' => Http::response([
                    'success' => false,
                    'errors' => [['message' => 'Invalid']],
                ]),
            ]);

            $service = new CloudflareService;
            $result = $service->createRecord('bad.example.com', '1.2.3.4');

            expect($result)->toBeNull();
        });
    });

    describe('deleteRecord', function () {
        it('sends DELETE request and returns true on success', function () {
            Http::fake([
                'api.cloudflare.com/client/v4/zones/zone-123/dns_records/record-1' => Http::response([
                    'success' => true,
                    'result' => ['id' => 'record-1'],
                ]),
            ]);

            $service = new CloudflareService;
            $result = $service->deleteRecord('record-1');

            expect($result)->toBeTrue();
        });
    });

    describe('listRecords', function () {
        it('returns records from cloudflare', function () {
            Http::fake([
                'api.cloudflare.com/client/v4/zones/zone-123/dns_records*' => Http::response([
                    'success' => true,
                    'result' => [
                        ['id' => '1', 'name' => 'a.example.com', 'type' => 'A', 'content' => '1.1.1.1'],
                        ['id' => '2', 'name' => 'b.example.com', 'type' => 'A', 'content' => '2.2.2.2'],
                    ],
                ]),
            ]);

            $service = new CloudflareService;
            $records = $service->listRecords();

            expect($records)->toHaveCount(2);
        });

        it('passes name filter', function () {
            Http::fake([
                'api.cloudflare.com/*' => Http::response([
                    'success' => true,
                    'result' => [],
                ]),
            ]);

            $service = new CloudflareService;
            $service->listRecords(name: 'specific.example.com');

            Http::assertSent(function ($request) {
                return str_contains($request->url(), 'name=specific.example.com');
            });
        });
    });

    describe('isDomainAvailable', function () {
        it('returns true when no records exist', function () {
            Http::fake([
                'api.cloudflare.com/*' => Http::response([
                    'success' => true,
                    'result' => [],
                ]),
            ]);

            $service = new CloudflareService;

            expect($service->isDomainAvailable('new.example.com'))->toBeTrue();
        });

        it('returns false when records exist', function () {
            Http::fake([
                'api.cloudflare.com/*' => Http::response([
                    'success' => true,
                    'result' => [
                        ['id' => '1', 'name' => 'taken.example.com'],
                    ],
                ]),
            ]);

            $service = new CloudflareService;

            expect($service->isDomainAvailable('taken.example.com'))->toBeFalse();
        });
    });

    describe('getZone', function () {
        it('returns zone details', function () {
            Http::fake([
                'api.cloudflare.com/client/v4/zones/zone-123' => Http::response([
                    'success' => true,
                    'result' => [
                        'id' => 'zone-123',
                        'name' => 'example.com',
                        'status' => 'active',
                    ],
                ]),
            ]);

            $service = new CloudflareService;
            $zone = $service->getZone();

            expect($zone['name'])->toBe('example.com');
            expect($zone['status'])->toBe('active');
        });
    });

    describe('setSslMode', function () {
        it('sends PATCH request with ssl mode', function () {
            Setting::set('cloudflare_api_token', 'test-token-123');

            Http::fake([
                'api.cloudflare.com/client/v4/zones/zone-123/settings/ssl' => Http::response([
                    'success' => true,
                ]),
            ]);

            $service = new CloudflareService;
            $result = $service->setSslMode('zone-123', 'full');

            expect($result)->toBeTrue();
        });
    });
});
