<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

final class SettingEncryptor
{
    private const SENSITIVE_KEYS = [
        'wg_easy_password',
        'cloudflare_api_token',
    ];

    private static ?self $instance = null;

    private ?Encrypter $encrypter = null;

    private ?string $keyPath = null;

    private bool $generating = false;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    public static function isSensitive(string $key): bool
    {
        return in_array($key, self::SENSITIVE_KEYS, true);
    }

    public function encrypt(string $value): string
    {
        return $this->getEncrypter()->encryptString($value);
    }

    public function decrypt(string $value): string
    {
        try {
            return $this->getEncrypter()->decryptString($value);
        } catch (DecryptException) {
            // Fall back to plain text for pre-existing unencrypted values
            return $value;
        }
    }

    public function generateKeyFile(): string
    {
        $this->generating = true;

        try {
            $path = $this->getKeyPath();

            if (! file_exists($path)) {
                $key = Encrypter::generateKey('aes-256-cbc');
                file_put_contents($path, base64_encode($key));
                chmod($path, 0600);
            }

            // Reset encrypter so it picks up the new key
            $this->encrypter = null;

            $this->encryptExistingValues();

            return $path;
        } finally {
            $this->generating = false;
        }
    }

    public function encryptExistingValues(): int
    {
        $count = 0;

        foreach (self::SENSITIVE_KEYS as $key) {
            $raw = \HardImpact\Orbit\Core\Models\Setting::query()
                ->where('key', $key)
                ->value('value');

            if ($raw === null || $raw === '') {
                continue;
            }

            // Check if already encrypted by attempting decrypt
            try {
                $this->getEncrypter()->decryptString($raw);

                continue; // Already encrypted
            } catch (DecryptException) {
                // Plain text — encrypt it
            }

            \HardImpact\Orbit\Core\Models\Setting::query()
                ->where('key', $key)
                ->update(['value' => $this->encrypt($raw)]);

            $count++;
        }

        return $count;
    }

    private function getEncrypter(): Encrypter
    {
        if ($this->encrypter === null) {
            $path = $this->getKeyPath();

            if (! file_exists($path)) {
                if ($this->generating) {
                    throw new \RuntimeException('Encryption key file does not exist and cannot be generated (already generating).');
                }
                $this->generateKeyFile();
            }

            $key = base64_decode(file_get_contents($path));
            $this->encrypter = new Encrypter($key, 'aes-256-cbc');
        }

        return $this->encrypter;
    }

    private function getKeyPath(): string
    {
        if ($this->keyPath === null) {
            $this->keyPath = config('orbit.config_path').'/encryption.key';
        }

        return $this->keyPath;
    }
}
