<?php

use App\Actions\Install\Shared\CreateDirectories;
use App\Data\Install\InstallContext;
use HardImpact\Orbit\Core\Services\SettingEncryptor;

require_once __DIR__.'/../Helpers/TestLogger.php';

beforeEach(function () {
    $this->testDir = sys_get_temp_dir().'/orbit-install-test-'.uniqid();

    // Pre-create encryption key file so generateKeyFile() skips key generation,
    // and stub encryptExistingValues to avoid database queries in unit tests
    $encryptor = SettingEncryptor::getInstance();
    $ref = new ReflectionProperty($encryptor, 'keyPath');
    $ref->setValue($encryptor, $this->testDir.'/encryption.key');
});

afterEach(function () {
    SettingEncryptor::setInstance(null);

    if (is_dir($this->testDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->testDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->testDir);
    }
});

it('creates all required directories', function () {
    // Pre-create the encryption key to avoid SettingEncryptor needing a database
    mkdir($this->testDir, 0755, true);
    $key = \Illuminate\Encryption\Encrypter::generateKey('aes-256-cbc');
    file_put_contents($this->testDir.'/encryption.key', base64_encode($key));

    $context = new InstallContext(
        configDir: $this->testDir,
        homeDir: dirname($this->testDir),
    );
    $logger = createTestLogger();

    $action = new CreateDirectories;
    $result = $action->handle($context, $logger);

    expect($result->isSuccess())->toBeTrue();
    expect(is_dir("{$this->testDir}/php"))->toBeTrue();
    expect(is_dir("{$this->testDir}/caddy"))->toBeTrue();
    expect(is_dir("{$this->testDir}/dns"))->toBeTrue();
    expect(is_dir("{$this->testDir}/postgres"))->toBeTrue();
    expect(is_dir("{$this->testDir}/redis"))->toBeTrue();
    expect(is_dir("{$this->testDir}/mailpit"))->toBeTrue();
    expect(is_dir("{$this->testDir}/logs"))->toBeTrue();
    expect(is_dir("{$this->testDir}/logs/provision"))->toBeTrue();
});

it('skips existing directories', function () {
    mkdir("{$this->testDir}/php", 0755, true);

    // Pre-create the encryption key to avoid SettingEncryptor needing a database
    $key = \Illuminate\Encryption\Encrypter::generateKey('aes-256-cbc');
    file_put_contents($this->testDir.'/encryption.key', base64_encode($key));

    $context = new InstallContext(
        configDir: $this->testDir,
        homeDir: dirname($this->testDir),
    );
    $logger = createTestLogger();

    $action = new CreateDirectories;
    $result = $action->handle($context, $logger);

    expect($result->isSuccess())->toBeTrue();
    expect(is_dir("{$this->testDir}/php"))->toBeTrue();
    expect(is_dir("{$this->testDir}/caddy"))->toBeTrue();
});
