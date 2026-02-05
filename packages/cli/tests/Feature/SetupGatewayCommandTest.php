<?php

use App\Services\IpValidator;

describe('IP validation via command', function () {
    it('rejects invalid IP addresses', function ($ip) {
        $this->artisan("setup:gateway '{$ip}' root --no-interaction")
            ->expectsOutputToContain('Invalid IP address')
            ->assertFailed();
    })->with([
        '256.1.1.1',
        '192.168.1',
        'abc.def.ghi.jkl',
        '',
    ]);
});

describe('IpValidator service', function () {
    it('is properly bound in the container', function () {
        $validator = app(IpValidator::class);
        expect($validator)->toBeInstanceOf(IpValidator::class);
    });

    it('validates the originally reported IP correctly', function () {
        $validator = app(IpValidator::class);
        $ip = '46.225.89.66';

        expect($validator->validate($ip))->toBeNull(
            "The IP {$ip} that was originally reported as invalid should now be valid"
        );
    });
});
