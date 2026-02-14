---
date: 2026-02-14
problem_type: security
component: orbit-core/Setting
severity: moderate
symptoms:
  - "Sensitive credentials visible in plain text via sqlite3 SELECT * FROM settings"
  - "API tokens and passwords appear in LLM conversation logs during debugging"
root_cause: Setting model stored all values as plain text, including credentials
tags: [encryption, laravel-zero, settings, credentials]
---

# Encrypting Sensitive Settings (Laravel Zero Compatible)

## Symptom
Credentials (WireGuard password, Cloudflare API token/zone ID) stored as plain text in SQLite. Running `sqlite3 ~/.config/orbit/database.sqlite "SELECT * FROM settings"` exposes all secrets. During LLM-assisted debugging, these values leak into conversation context.

## Investigation
1. Attempted: Laravel's built-in `encrypt()`/`decrypt()` helpers
   Result: `Target class [encrypter] does not exist` — Laravel Zero doesn't register `EncryptionServiceProvider` or configure `APP_KEY`
2. Attempted: Using `APP_KEY` from app package
   Result: CLI and app share the same SQLite DB but run in different processes — no shared Laravel config

## Root Cause
The `Setting` model in `orbit-core` is shared by both the full Laravel app (has `APP_KEY` + encrypter service) and Laravel Zero CLI (has neither). Need encryption that works in both contexts without depending on the service container.

## Solution

### 1. Direct `Encrypter` instantiation with file-based key

Created `SettingEncryptor` that instantiates `Illuminate\Encryption\Encrypter` directly — no service provider needed:

```php
// packages/core/src/Services/SettingEncryptor.php
use Illuminate\Encryption\Encrypter;

final class SettingEncryptor
{
    private const SENSITIVE_KEYS = [
        'wg_easy_password',
        'cloudflare_api_token',
        'cloudflare_zone_id',
    ];

    private function getEncrypter(): Encrypter
    {
        $key = base64_decode(file_get_contents($this->getKeyPath()));
        return new Encrypter($key, 'aes-256-cbc');
    }
}
```

Key file lives at `~/.config/orbit/encryption.key` (same location on all nodes sharing the DB).

### 2. Transparent integration in Setting model

```php
// Before
public static function get(string $key, mixed $default = null): mixed
{
    $setting = static::find($key);
    return $setting !== null ? $setting->value : $default;
}

// After
public static function get(string $key, mixed $default = null): mixed
{
    $setting = static::find($key);
    if ($setting === null) return $default;
    $value = $setting->value;
    if ($value !== null && SettingEncryptor::isSensitive($key)) {
        $value = SettingEncryptor::getInstance()->decrypt($value);
    }
    return $value;
}
```

### 3. Plain text fallback for migration

The `decrypt()` method catches `DecryptException` and returns the raw value. This means pre-existing plain text values are still readable — no database migration needed.

```php
public function decrypt(string $value): string
{
    try {
        return $this->getEncrypter()->decryptString($value);
    } catch (DecryptException) {
        return $value; // Fall back to plain text
    }
}
```

### 4. Auto-generate key and encrypt existing values

`generateKeyFile()` creates the key and immediately calls `encryptExistingValues()` which reads all sensitive keys from DB and re-writes them encrypted.

## Prevention
- When adding new sensitive settings, add the key name to `SettingEncryptor::SENSITIVE_KEYS`
- Never use Laravel's `encrypt()`/`decrypt()` helpers or `Crypt` facade in orbit-core — they're unavailable in CLI context
- The encryption key file must exist on every node that shares the SQLite database

## Related
- `packages/cli/AGENTS.md` — "No encrypt()/decrypt() helpers in Laravel Zero" gotcha (updated)
- `packages/core/src/Services/SettingEncryptor.php` — implementation
- `packages/core/tests/Unit/Services/SettingEncryptorTest.php` — tests
- `packages/core/tests/Unit/Models/SettingTest.php` — integration tests
