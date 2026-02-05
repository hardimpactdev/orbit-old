<?php

use App\Services\IpValidator;

beforeEach(function () {
    $this->validator = new IpValidator;
});

it('accepts valid public IPv4 addresses', function () {
    $validIps = [
        '46.225.89.66',
        '8.8.8.8',
        '203.0.113.10',
        '104.16.249.249',
        '1.1.1.1',
        '142.250.185.78',
        '0.0.0.0',
    ];

    foreach ($validIps as $ip) {
        expect($this->validator->validate($ip))
            ->toBeNull("Expected {$ip} to be valid, but got error: ".($this->validator->validate($ip) ?? 'none'));
    }
});

it('accepts valid private IPv4 addresses for testing', function () {
    $privateIps = [
        '192.168.1.1',
        '10.0.0.1',
        '172.16.0.1',
        '172.31.255.255',
        '127.0.0.1',
    ];

    foreach ($privateIps as $ip) {
        expect($this->validator->validate($ip))
            ->toBeNull("Expected {$ip} to be valid, but got error: ".($this->validator->validate($ip) ?? 'none'));
    }
});

it('accepts valid IPv6 addresses', function () {
    $validIpv6s = [
        '2001:4860:4860::8888',
        '2606:4700:4700::1111',
        '::1',
        'fe80::1',
        '2001:db8::1',
    ];

    foreach ($validIpv6s as $ip) {
        expect($this->validator->validate($ip))
            ->toBeNull("Expected {$ip} to be valid, but got error: ".($this->validator->validate($ip) ?? 'none'));
    }
});

it('rejects invalid IP addresses', function ($ip) {
    expect($this->validator->validate($ip))->not->toBeNull();
})->with([
    '256.1.1.1',       // Out of range
    '192.168.1',       // Missing octet
    '192.168.1.1.1',   // Extra octet
    'abc.def.ghi.jkl', // Non-numeric
    '',                // Empty string
    'not-an-ip',       // Text
    '192.168.1.1/24',  // With CIDR
    ' 192.168.1.1 ',   // With whitespace
]);

it('returns error for empty string', function () {
    expect($this->validator->validate(''))->toBe('IP address is required');
});

it('returns error for invalid format', function () {
    expect($this->validator->validate('not-an-ip'))->toBe('Invalid IP address format');
});

it('specifically validates the reported issue IP 46.225.89.66', function () {
    $ip = '46.225.89.66';
    $result = $this->validator->validate($ip);

    expect($result)->toBeNull(
        "IP {$ip} should be valid but was rejected. ".
        'This was the originally reported bug.'
    );
});
