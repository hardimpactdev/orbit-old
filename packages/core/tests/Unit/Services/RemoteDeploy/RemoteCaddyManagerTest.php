<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Services\RemoteDeploy\RemoteCaddyManager;

describe('RemoteCaddyManager TEMPLATE', function () {
    beforeEach(function () {
        $reflection = new ReflectionClass(RemoteCaddyManager::class);
        $this->template = $reflection->getReflectionConstant('TEMPLATE')->getValue();
    });

    it('contains security headers', function () {
        expect($this->template)->toContain('X-Content-Type-Options "nosniff"');
        expect($this->template)->toContain('X-Frame-Options "DENY"');
        expect($this->template)->toContain('X-XSS-Protection "1; mode=block"');
        expect($this->template)->toContain('Referrer-Policy "strict-origin-when-cross-origin"');
        expect($this->template)->toContain('Permissions-Policy "camera=(), microphone=(), geolocation=()"');
        expect($this->template)->toContain('-Server');
    });

    it('contains HSTS header for production', function () {
        expect($this->template)->toContain('Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"');
    });

    it('contains path blocking rules', function () {
        expect($this->template)->toContain('@blocked path');
        expect($this->template)->toContain('/.env');
        expect($this->template)->toContain('/.git/*');
        expect($this->template)->toContain('/vendor/*');
        expect($this->template)->toContain('/node_modules/*');
        expect($this->template)->toContain('respond @blocked 404');
    });

    it('contains security.txt handle block', function () {
        expect($this->template)->toContain('handle /.well-known/security.txt');
        expect($this->template)->toContain('Content-Type "text/plain"');
        expect($this->template)->toContain('Contact: mailto:');
        expect($this->template)->toContain('Expires:');
    });

    it('contains cache headers for static build assets', function () {
        expect($this->template)->toContain('@static');
        expect($this->template)->toContain('path /build/*');
        expect($this->template)->toContain('Cache-Control "public, max-age=31536000, immutable"');
    });
});
