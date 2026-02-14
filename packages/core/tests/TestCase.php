<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Tests;

use HardImpact\Orbit\Core\OrbitCoreServiceProvider;
use HardImpact\Orbit\Core\Services\SettingEncryptor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    private static ?string $tempConfigPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'HardImpact\\Orbit\\Core\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // Reset singleton so each test gets a fresh encryptor
        SettingEncryptor::setInstance(null);
    }

    protected function tearDown(): void
    {
        SettingEncryptor::setInstance(null);

        if (self::$tempConfigPath !== null && is_dir(self::$tempConfigPath)) {
            $keyFile = self::$tempConfigPath.'/encryption.key';
            if (file_exists($keyFile)) {
                unlink($keyFile);
            }
            rmdir(self::$tempConfigPath);
            self::$tempConfigPath = null;
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            OrbitCoreServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Point orbit config path to a temp directory for encryption key
        self::$tempConfigPath = sys_get_temp_dir().'/orbit-test-'.getmypid();
        if (! is_dir(self::$tempConfigPath)) {
            mkdir(self::$tempConfigPath, 0755, true);
        }
        config()->set('orbit.config_path', self::$tempConfigPath);

        // Run migrations for testing
        foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__.'/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
        }
    }
}
