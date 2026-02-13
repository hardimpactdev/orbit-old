---
date: 2026-02-13
problem_type: build-error
component: packages/cli/app/Commands/UpgradeCommand.php
severity: critical
symptoms:
  - "Downloaded file is not a valid binary."
  - orbit upgrade downloads correct static binary but rejects it
root_cause: Mach-O magic byte check only had big-endian values, ARM64 macOS uses little-endian
tags: [mach-o, binary-validation, endianness, upgrade]
---

# Mach-O Binary Validation Fails on ARM64 macOS

## Symptom

`orbit upgrade` downloads the correct `orbit-macos-aarch64` static binary but rejects it:

```
Downloading v0.1.101...
Downloaded file is not a valid binary.
```

## Investigation

1. Downloaded binary manually and inspected magic bytes:
   ```
   $ head -c 16 orbit-macos-aarch64 | xxd
   00000000: cffa edfe 0c00 0001 ...
   ```
   First 4 bytes: `CF FA ED FE`

2. The validation code used `unpack('N', ...)` which reads big-endian:
   `CF FA ED FE` → `0xCFFAEDFE`

3. Check list only contained: `0xFEEDFACF`, `0xCAFEBABE`, `0xBEBAFECA`
   Missing: `0xCFFAEDFE` (MH_CIGAM_64)

## Root Cause

ARM64 macOS is little-endian. Mach-O stores its magic number in native byte order.
`unpack('N', ...)` reads as big-endian (network order), so a little-endian Mach-O 64-bit
binary reads as `0xCFFAEDFE` (MH_CIGAM_64), not `0xFEEDFACF` (MH_MAGIC_64).

The validation only checked big-endian magic values, missing all CIGAM (reversed) variants.

## Solution

```php
// Before (broken) — only big-endian values
return $magic && in_array($magic[1], [0xFEEDFACF, 0xCAFEBABE, 0xBEBAFECA], true);

// After (fixed) — all Mach-O magic values, both endiannesses
return $magic && in_array($magic[1], [
    0xFEEDFACE, 0xCEFAEDFE, // MH_MAGIC / MH_CIGAM (32-bit)
    0xFEEDFACF, 0xCFFAEDFE, // MH_MAGIC_64 / MH_CIGAM_64 (64-bit)
    0xCAFEBABE, 0xBEBAFECA, // FAT_MAGIC / FAT_CIGAM (universal)
], true);
```

## Prevention

- When checking Mach-O magic bytes with big-endian `unpack('N', ...)`, always include both MAGIC and CIGAM variants
- Reference: https://opensource.apple.com/source/xnu/xnu-4570.71.2/EXTERNAL_HEADERS/mach-o/loader.h
- The complete set of Mach-O magic values is: FEEDFACE, CEFAEDFE, FEEDFACF, CFFAEDFE, CAFEBABE, BEBAFECA
