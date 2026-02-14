<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Enums\NodeEnvironment;

describe('NodeEnvironment', function () {
    it('has all expected cases', function () {
        $cases = array_map(fn ($c) => $c->value, NodeEnvironment::cases());

        expect($cases)->toBe(['development', 'staging', 'production']);
    });

    it('can be created from string values', function () {
        expect(NodeEnvironment::from('development'))->toBe(NodeEnvironment::Development);
        expect(NodeEnvironment::from('staging'))->toBe(NodeEnvironment::Staging);
        expect(NodeEnvironment::from('production'))->toBe(NodeEnvironment::Production);
    });
});
