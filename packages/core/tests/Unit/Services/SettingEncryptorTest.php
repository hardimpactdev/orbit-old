<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Services\SettingEncryptor;

describe('SettingEncryptor', function () {
    describe('isSensitive', function () {
        it('identifies sensitive keys', function () {
            expect(SettingEncryptor::isSensitive('wg_easy_password'))->toBeTrue();
            expect(SettingEncryptor::isSensitive('cloudflare_api_token'))->toBeTrue();
        });

        it('identifies non-sensitive keys', function () {
            expect(SettingEncryptor::isSensitive('editor_scheme'))->toBeFalse();
            expect(SettingEncryptor::isSensitive('terminal'))->toBeFalse();
            expect(SettingEncryptor::isSensitive('ssh_public_key'))->toBeFalse();
        });
    });

    describe('encrypt and decrypt', function () {
        it('roundtrips a value', function () {
            $encryptor = SettingEncryptor::getInstance();

            $encrypted = $encryptor->encrypt('my-secret-token');

            expect($encrypted)->not->toBe('my-secret-token');
            expect($encryptor->decrypt($encrypted))->toBe('my-secret-token');
        });

        it('produces different ciphertext each time', function () {
            $encryptor = SettingEncryptor::getInstance();

            $a = $encryptor->encrypt('same-value');
            $b = $encryptor->encrypt('same-value');

            expect($a)->not->toBe($b);
        });
    });

    describe('plain text fallback', function () {
        it('returns plain text when value cannot be decrypted', function () {
            $encryptor = SettingEncryptor::getInstance();

            expect($encryptor->decrypt('plain-text-value'))->toBe('plain-text-value');
        });
    });

    describe('key file generation', function () {
        it('generates key file with correct permissions', function () {
            $encryptor = SettingEncryptor::getInstance();

            $path = $encryptor->generateKeyFile();

            expect(file_exists($path))->toBeTrue();
            expect(decoct(fileperms($path) & 0777))->toBe('600');
        });

        it('does not overwrite existing key file', function () {
            $encryptor = SettingEncryptor::getInstance();

            $path = $encryptor->generateKeyFile();
            $firstKey = file_get_contents($path);

            $encryptor->generateKeyFile();
            $secondKey = file_get_contents($path);

            expect($secondKey)->toBe($firstKey);
        });
    });
});
