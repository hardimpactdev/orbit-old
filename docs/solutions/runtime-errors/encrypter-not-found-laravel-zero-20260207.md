---
date: 2026-02-07
problem_type: runtime-error
component: packages/cli (Laravel Zero)
severity: moderate
symptoms:
  - "Target class [encrypter] does not exist."
  - "Class \"encrypter\" does not exist"
root_cause: Laravel Zero doesn't configure an APP_KEY or register the encryption service by default
tags: [laravel-zero, encryption, gateway]
---

# encrypt()/decrypt() fails in Laravel Zero CLI

## Symptom

Running `orbit gateway:set-password` on the gateway server:

```
In Container.php line 1124:
  Target class [encrypter] does not exist.
```

## Investigation

1. Attempted: Using `encrypt($password)` / `decrypt($encrypted)` to store wg-easy password securely in the settings table.
   Result: Laravel Zero doesn't ship with an `APP_KEY` in config/app.php, and the `Illuminate\Encryption\EncryptionServiceProvider` is not registered. The `encrypt()` helper requires both.

2. Considered: Adding a static APP_KEY to config/app.php.
   Result: Would work but adds complexity for no real security gain — the key would be embedded in the distributed binary.

## Root Cause

Laravel Zero is a stripped-down framework. Unlike full Laravel, it doesn't include:
- `APP_KEY` in config
- `EncryptionServiceProvider` in the default providers
- `config/encryption.php`

The `encrypt()`/`decrypt()` helpers depend on the `encrypter` service which is never registered.

## Solution

Store the password in plain text via `Setting::set('wg_easy_password', $password)`.

The SQLite database lives on the gateway server at `~/.config/orbit/database.sqlite`, accessible only to the `gateway` user. Encryption adds no meaningful security since the key would be in the binary itself.

```php
// Before (broken)
Setting::set('wg_easy_password', encrypt($password));
$password = decrypt(Setting::get('wg_easy_password'));

// After (working)
Setting::set('wg_easy_password', $password);
$password = Setting::get('wg_easy_password');
```

## Prevention

- Don't use `encrypt()`/`decrypt()` in the CLI without first verifying the encrypter service is available
- For secrets that only need protection from casual inspection on a server, plain text in a user-owned SQLite DB is acceptable
- If real encryption is needed in Laravel Zero, register `EncryptionServiceProvider` and set a key in config/app.php
