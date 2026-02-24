<?php

use App\Services\CaddyfileGenerator;
use App\Services\ConfigManager;
use App\Services\PhpManager;
use App\Services\ProjectScanner;
use App\Services\ServiceManager;
use App\Services\WorktreeService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/orbit-caddy-test-'.uniqid();
    mkdir($this->tempDir.'/caddy', 0755, true);
    mkdir($this->tempDir.'/php', 0755, true);

    $this->configManager = Mockery::mock(ConfigManager::class);
    $this->projectScanner = Mockery::mock(ProjectScanner::class);
    $this->serviceManager = Mockery::mock(ServiceManager::class);
    $this->phpManager = Mockery::mock(PhpManager::class);
    $this->worktreeService = Mockery::mock(WorktreeService::class);

    $this->configManager->shouldReceive('getConfigPath')->andReturn($this->tempDir);
    $this->configManager->shouldReceive('isServiceEnabled')->andReturn(false);
    $this->configManager->shouldReceive('getWebAppPath')->andReturn('');
    $this->configManager->shouldReceive('get')->andReturnUsing(function ($key, $default = null) {
        return $default;
    });

    // Mock PhpManager socket paths
    $this->phpManager->shouldReceive('getSocketPath')->with('8.3')->andReturn($this->tempDir.'/php/php83.sock');
    $this->phpManager->shouldReceive('getSocketPath')->with('8.4')->andReturn($this->tempDir.'/php/php84.sock');
    $this->phpManager->shouldReceive('getSocketPath')->andReturn($this->tempDir.'/php/php83.sock');

    // Mock WorktreeService
    $this->worktreeService->shouldReceive('getLinkedWorktreesForCaddy')->andReturn([]);

    // Default: Reverb disabled
    $this->serviceManager->shouldReceive('isEnabled')->with('reverb')->andReturn(false);
});

afterEach(function () {
    File::deleteDirectory($this->tempDir);
});

it('generates caddyfile with sites', function () {
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getPaths')->andReturn(['~/Projects']);

    $this->projectScanner->shouldReceive('scanProjects')->andReturn([
        [
            'name' => 'mysite',
            'domain' => 'mysite.test',
            'path' => '/home/user/Projects/mysite',
            'php_version' => '8.3',
            'has_custom_php' => false,
        ],
        [
            'name' => 'another',
            'domain' => 'another.test',
            'path' => '/home/user/Projects/another',
            'php_version' => '8.4',
            'has_custom_php' => true,
        ],
    ]);

    $generator = new CaddyfileGenerator($this->configManager, $this->projectScanner, $this->phpManager, $this->worktreeService, $this->serviceManager);
    $generator->generate();

    $caddyfile = File::get($this->tempDir.'/caddy/Caddyfile');

    expect($caddyfile)->toContain('local_certs');
    expect($caddyfile)->toContain('mysite.test');
    expect($caddyfile)->toContain('another.test');
    // Caddyfile uses unix sockets for PHP-FPM
    expect($caddyfile)->toContain('php_fastcgi unix/');
    expect($caddyfile)->toContain('php83.sock');
    expect($caddyfile)->toContain('php84.sock');
});

it('generates caddyfile with php_fastcgi directives', function () {
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getPaths')->andReturn(['~/Projects']);

    $this->projectScanner->shouldReceive('scanProjects')->andReturn([
        [
            'name' => 'mysite',
            'domain' => 'mysite.test',
            'path' => '/home/user/Projects/mysite',
            'php_version' => '8.3',
            'has_custom_php' => false,
        ],
    ]);

    $generator = new CaddyfileGenerator($this->configManager, $this->projectScanner, $this->phpManager, $this->worktreeService, $this->serviceManager);
    $generator->generate();

    $caddyfile = File::get($this->tempDir.'/caddy/Caddyfile');

    expect($caddyfile)->toContain('mysite.test');
    expect($caddyfile)->toContain('/public');
    expect($caddyfile)->toContain('php_fastcgi');
    expect($caddyfile)->toContain('file_server');
});

it('includes security header and path blocking snippets', function () {
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getPaths')->andReturn(['~/Projects']);

    $this->projectScanner->shouldReceive('scanProjects')->andReturn([
        [
            'name' => 'mysite',
            'domain' => 'mysite.test',
            'path' => '/home/user/Projects/mysite',
            'php_version' => '8.3',
            'has_custom_php' => false,
        ],
    ]);

    $generator = new CaddyfileGenerator($this->configManager, $this->projectScanner, $this->phpManager, $this->worktreeService, $this->serviceManager);
    $generator->generate();

    $caddyfile = File::get($this->tempDir.'/caddy/Caddyfile');

    // Verify snippets are defined
    expect($caddyfile)->toContain('(security_headers)');
    expect($caddyfile)->toContain('X-Content-Type-Options "nosniff"');
    expect($caddyfile)->toContain('X-Frame-Options "DENY"');
    expect($caddyfile)->toContain('(path_blocking)');
    expect($caddyfile)->toContain('/.env');
    expect($caddyfile)->toContain('respond @blocked 404');
    expect($caddyfile)->toContain('(security_headers_production)');

    // Verify site blocks import snippets
    expect($caddyfile)->toContain('import security_headers');
    expect($caddyfile)->toContain('import path_blocking');

    // Dev sites must NOT have HSTS in their site block (only in snippet definition)
    // The security_headers snippet (used by dev) does not include HSTS
    expect($caddyfile)->not->toContain('import security_headers_production');

    // Verify security_txt snippet is defined and imported
    expect($caddyfile)->toContain('(security_txt)');
    expect($caddyfile)->toContain('import security_txt');
    expect($caddyfile)->toContain('handle /.well-known/security.txt');
    expect($caddyfile)->toContain('Contact: mailto:');
});

it('includes cache headers snippet and import', function () {
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getPaths')->andReturn(['~/Projects']);

    $this->projectScanner->shouldReceive('scanProjects')->andReturn([
        [
            'name' => 'mysite',
            'domain' => 'mysite.test',
            'path' => '/home/user/Projects/mysite',
            'php_version' => '8.3',
            'has_custom_php' => false,
        ],
    ]);

    $generator = new CaddyfileGenerator($this->configManager, $this->projectScanner, $this->phpManager, $this->worktreeService, $this->serviceManager);
    $generator->generate();

    $caddyfile = File::get($this->tempDir.'/caddy/Caddyfile');

    // Verify snippet is defined
    expect($caddyfile)->toContain('(cache_headers)');
    expect($caddyfile)->toContain('@static');
    expect($caddyfile)->toContain('path /build/*');
    expect($caddyfile)->toContain('Cache-Control "public, max-age=31536000, immutable"');

    // Verify site blocks import the snippet
    expect($caddyfile)->toContain('import cache_headers');
});

it('generates empty caddyfile when no sites exist', function () {
    $this->configManager->shouldReceive('getDefaultPhpVersion')->andReturn('8.3');
    $this->configManager->shouldReceive('getPaths')->andReturn([]);

    $this->projectScanner->shouldReceive('scanProjects')->andReturn([]);

    $generator = new CaddyfileGenerator($this->configManager, $this->projectScanner, $this->phpManager, $this->worktreeService, $this->serviceManager);
    $generator->generate();

    $caddyfile = File::get($this->tempDir.'/caddy/Caddyfile');

    expect($caddyfile)->toContain('local_certs');
    // No site entries when no projects exist (Reverb is disabled in mock)
    expect($caddyfile)->not->toContain('mysite');
});
