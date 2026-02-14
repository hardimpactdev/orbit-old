<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Services\SettingEncryptor;

describe('Setting encryption', function () {
    describe('sensitive values', function () {
        it('encrypts sensitive values in the database', function () {
            Setting::set('wg_easy_password', 'super-secret');

            $raw = Setting::query()->where('key', 'wg_easy_password')->value('value');

            expect($raw)->not->toBe('super-secret');
            expect($raw)->not->toBeNull();
        });

        it('transparently decrypts on read', function () {
            Setting::set('cloudflare_api_token', 'cf-token-123');

            expect(Setting::get('cloudflare_api_token'))->toBe('cf-token-123');
        });

        it('handles null values for sensitive keys', function () {
            Setting::set('cloudflare_zone_id', null);

            expect(Setting::get('cloudflare_zone_id'))->toBeNull();
        });

        it('returns default when sensitive key does not exist', function () {
            expect(Setting::get('wg_easy_password', 'fallback'))->toBe('fallback');
        });
    });

    describe('non-sensitive values', function () {
        it('stores non-sensitive values as plain text', function () {
            Setting::set('editor_scheme', 'cursor');

            $raw = Setting::query()->where('key', 'editor_scheme')->value('value');

            expect($raw)->toBe('cursor');
        });
    });

    describe('migration from plain text', function () {
        it('reads pre-existing plain text sensitive values', function () {
            // Simulate a pre-existing plain text value in the database
            Setting::query()->insert([
                'key' => 'wg_easy_password',
                'value' => 'old-plain-password',
            ]);

            expect(Setting::get('wg_easy_password'))->toBe('old-plain-password');
        });

        it('encrypts existing plain text values via encryptExistingValues', function () {
            Setting::query()->insert([
                'key' => 'cloudflare_api_token',
                'value' => 'plain-token',
            ]);

            $count = SettingEncryptor::getInstance()->encryptExistingValues();

            expect($count)->toBe(1);

            $raw = Setting::query()->where('key', 'cloudflare_api_token')->value('value');
            expect($raw)->not->toBe('plain-token');

            // Still readable through Setting::get()
            expect(Setting::get('cloudflare_api_token'))->toBe('plain-token');
        });
    });
});
