<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use HardImpact\Orbit\Core\Models\Deployment;
use HardImpact\Orbit\Core\Models\Node;
use HardImpact\Orbit\Core\Services\TldService;

beforeEach(function () {
    $this->service = new TldService;
});

describe('TldService', function () {
    describe('updateNodeTld', function () {
        it('updates node tld and custom_tld', function () {
            $node = Node::factory()->client()->create([
                'tld' => 'ccc',
                'custom_tld' => 'ccc',
                'vpn_ip' => '10.6.0.4',
            ]);

            $result = $this->service->updateNodeTld($node, 'bear');

            $node->refresh();
            expect($node->tld)->toBe('bear');
            expect($node->custom_tld)->toBe('bear');
            expect($result['old_tld'])->toBe('ccc');
            expect($result['new_tld'])->toBe('bear');
        });

        it('updates active deployment domains with old TLD suffix', function () {
            $node = Node::factory()->client()->create([
                'tld' => 'ccc',
                'custom_tld' => 'ccc',
            ]);

            $deployment = Deployment::factory()->active()->create([
                'node_id' => $node->id,
                'domain' => 'myapp.ccc',
                'url' => 'https://myapp.ccc',
            ]);

            $result = $this->service->updateNodeTld($node, 'bear');

            $deployment->refresh();
            expect($deployment->domain)->toBe('myapp.bear');
            expect($deployment->url)->toBe('https://myapp.bear');
            expect($result['deployments_updated'])->toBe(1);
        });

        it('skips production deployments with non-TLD domains', function () {
            $node = Node::factory()->client()->create([
                'tld' => 'ccc',
                'custom_tld' => 'ccc',
            ]);

            $prodDeployment = Deployment::factory()->active()->create([
                'node_id' => $node->id,
                'domain' => 'srpm.nl',
                'url' => 'https://srpm.nl',
            ]);

            $tldDeployment = Deployment::factory()->active()->create([
                'node_id' => $node->id,
                'domain' => 'myapp.ccc',
                'url' => 'https://myapp.ccc',
            ]);

            $result = $this->service->updateNodeTld($node, 'bear');

            $prodDeployment->refresh();
            expect($prodDeployment->domain)->toBe('srpm.nl');
            expect($prodDeployment->url)->toBe('https://srpm.nl');

            $tldDeployment->refresh();
            expect($tldDeployment->domain)->toBe('myapp.bear');
            expect($result['deployments_updated'])->toBe(1);
        });

        it('skips removed and failed deployments', function () {
            $node = Node::factory()->client()->create([
                'tld' => 'ccc',
                'custom_tld' => 'ccc',
            ]);

            $removed = Deployment::factory()->removed()->create([
                'node_id' => $node->id,
                'domain' => 'old.ccc',
                'url' => 'https://old.ccc',
            ]);

            $failed = Deployment::factory()->failed()->create([
                'node_id' => $node->id,
                'domain' => 'broken.ccc',
                'url' => 'https://broken.ccc',
            ]);

            $result = $this->service->updateNodeTld($node, 'bear');

            $removed->refresh();
            $failed->refresh();
            expect($removed->domain)->toBe('old.ccc');
            expect($failed->domain)->toBe('broken.ccc');
            expect($result['deployments_updated'])->toBe(0);
        });

        it('returns no-op when old equals new', function () {
            $node = Node::factory()->client()->create([
                'tld' => 'bear',
                'custom_tld' => 'bear',
            ]);

            $result = $this->service->updateNodeTld($node, 'bear');

            expect($result['old_tld'])->toBe('bear');
            expect($result['new_tld'])->toBe('bear');
            expect($result['deployments_updated'])->toBe(0);
        });

        it('rejects invalid TLDs', function () {
            $node = Node::factory()->client()->create(['tld' => 'ccc']);

            $this->service->updateNodeTld($node, 'invalid tld!');
        })->throws(\InvalidArgumentException::class, 'TLD must contain only lowercase letters, numbers, and hyphens');

        it('rejects reserved TLDs', function () {
            $node = Node::factory()->client()->create(['tld' => 'ccc']);

            $this->service->updateNodeTld($node, 'com');
        })->throws(\InvalidArgumentException::class, 'Cannot use reserved TLD: com');

        it('handles node with no deployments', function () {
            $node = Node::factory()->client()->create([
                'tld' => 'ccc',
                'custom_tld' => 'ccc',
            ]);

            $result = $this->service->updateNodeTld($node, 'bear');

            $node->refresh();
            expect($node->tld)->toBe('bear');
            expect($result['deployments_updated'])->toBe(0);
        });

        it('leaves custom_tld null when it was null', function () {
            $node = Node::factory()->client()->create([
                'tld' => 'test',
                'custom_tld' => null,
            ]);

            $result = $this->service->updateNodeTld($node, 'bear');

            $node->refresh();
            expect($node->tld)->toBe('bear');
            expect($node->custom_tld)->toBeNull();
        });

        it('normalizes TLD input', function () {
            $node = Node::factory()->client()->create([
                'tld' => 'ccc',
                'custom_tld' => 'ccc',
            ]);

            $result = $this->service->updateNodeTld($node, '.BEAR');

            $node->refresh();
            expect($node->tld)->toBe('bear');
            expect($result['new_tld'])->toBe('bear');
        });

        it('updates multiple deployments', function () {
            $node = Node::factory()->client()->create([
                'tld' => 'ccc',
                'custom_tld' => 'ccc',
            ]);

            Deployment::factory()->active()->create([
                'node_id' => $node->id,
                'domain' => 'app1.ccc',
                'url' => 'https://app1.ccc',
            ]);

            Deployment::factory()->active()->create([
                'node_id' => $node->id,
                'domain' => 'app2.ccc',
                'url' => 'https://app2.ccc',
            ]);

            Deployment::factory()->active()->create([
                'node_id' => $node->id,
                'domain' => 'app3.ccc',
                'url' => 'https://app3.ccc',
            ]);

            $result = $this->service->updateNodeTld($node, 'bear');

            expect($result['deployments_updated'])->toBe(3);

            $domains = Deployment::where('node_id', $node->id)->pluck('domain')->toArray();
            expect($domains)->each->toEndWith('.bear');
        });
    });

    describe('validateTld', function () {
        it('accepts valid TLDs', function () {
            expect($this->service->validateTld('bear'))->toBeNull();
            expect($this->service->validateTld('my-tld'))->toBeNull();
            expect($this->service->validateTld('staging123'))->toBeNull();
        });

        it('rejects empty TLDs', function () {
            expect($this->service->validateTld(''))->toBe('TLD cannot be empty');
        });

        it('rejects TLDs with invalid characters', function () {
            expect($this->service->validateTld('my tld'))->not->toBeNull();
            expect($this->service->validateTld('my.tld'))->not->toBeNull();
            expect($this->service->validateTld('TLD!'))->not->toBeNull();
        });

        it('rejects too short TLDs', function () {
            expect($this->service->validateTld('a'))->toBe('TLD must be between 2 and 63 characters');
        });

        it('rejects reserved TLDs', function () {
            expect($this->service->validateTld('com'))->toBe('Cannot use reserved TLD: com');
            expect($this->service->validateTld('dev'))->toBe('Cannot use reserved TLD: dev');
            expect($this->service->validateTld('io'))->toBe('Cannot use reserved TLD: io');
        });
    });
});
