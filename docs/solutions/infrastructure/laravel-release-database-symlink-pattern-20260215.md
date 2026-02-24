---
date: 2026-02-15
problem_type: infrastructure
component: Laravel / Zero-Downtime Deployment
severity: critical
symptoms:
  - "php artisan migrate reports 'nothing to migrate'"
  - "migrations/ directory empty after deploy"
  - "Database tables missing but migrations claim to be up-to-date"
root_cause: "Symlinking entire database/ directory overwrites migrations from release"
tags: [laravel, deployment, symlinks, migrations, zero-downtime]
---

# Laravel Release-Based Deployment: Database Directory Symlink Kills Migrations

## Symptom

In a Laravel zero-downtime deployment using release directories and symlinks:

```
~/projects/app/
├── releases/
│   ├── 20260215_120000/
│   └── 20260215_143000/  ← current release
├── current → releases/20260215_143000
├── .env
├── storage/  ← shared
└── database/  ← shared (PROBLEM!)
```

After deployment:
```bash
cd current
php artisan migrate
# Nothing to migrate ❌

ls database/
# database.sqlite  ← Only the DB file, no migrations/
```

**Impact**: Migrations don't run. Missing database tables cause 500 errors.

## Investigation

### Attempted: Check if migrations exist in repo
```bash
cd releases/20260215_143000
ls database/
# migrations/  ← They exist in the release! ✓
```

**Result**: Migrations are in the release, but the symlink overwrites them.

### Attempted: Run migrations from release directory
```bash
cd releases/20260215_143000
php artisan migrate
# Works! ✓
```

**Result**: Migrations run when executed from the release. The symlink is the problem.

### Root Cause Found

The deployment creates a symlink from the release to shared storage:

```php
// Broken approach
$links = [
    '.env' => '../../.env',
    'storage' => '../../storage',
    'database' => '../../database',  // ❌ Symlinks ENTIRE directory
];

foreach ($links as $name => $target) {
    symlink($target, "{$releasePath}/{$name}");
}
```

**What happens**:
1. Release cloned with `database/migrations/`, `database/factories/`, `database/seeders/`
2. Symlink created: `current/database → ../../database`
3. Shared `database/` only contains `database.sqlite`
4. Symlink **replaces** the release's `database/` directory
5. Migrations gone!

```bash
# Result
current/database/
└── database.sqlite  ← Only file from shared storage

# Lost
# ├── migrations/  ← From release, now inaccessible
# ├── factories/   ← From release, now inaccessible
# └── seeders/     ← From release, now inaccessible
```

## Root Cause

Symlinking the **entire `database/` directory** overwrites version-controlled code (migrations/factories/seeders) with shared storage (just the SQLite file).

**What should be shared**:
- ✓ `database/database.sqlite` - The actual database file

**What should NOT be shared**:
- ✗ `database/migrations/` - Version-controlled, changes per release
- ✗ `database/factories/` - Version-controlled, changes per release
- ✗ `database/seeders/` - Version-controlled, changes per release

## Solution

**Only symlink the SQLite file, not the entire directory:**

```php
// packages/cli/app/Commands/ProjectDeployCommand.php

private function createReleaseSymlinks(string $basePath, string $releasePath): void
{
    // Symlink .env and storage (full directories - no code inside)
    $links = [
        '.env' => '../../.env',
        'storage' => '../../storage',
        // database NOT here anymore!
    ];

    foreach ($links as $name => $target) {
        $linkPath = "{$releasePath}/{$name}";

        if (is_dir($linkPath) && ! is_link($linkPath)) {
            Process::run("rm -rf " . escapeshellarg($linkPath));
        } elseif (file_exists($linkPath) || is_link($linkPath)) {
            unlink($linkPath);
        }

        symlink($target, $linkPath);
    }

    // NEW: Special handling for database
    $this->ensureDatabaseStructure($basePath, $releasePath);
}

private function ensureDatabaseStructure(string $basePath, string $releasePath): void
{
    // Ensure shared database directory exists
    $sharedDbDir = "{$basePath}/database";
    if (! is_dir($sharedDbDir)) {
        mkdir($sharedDbDir, 0755, true);
    }

    // Only symlink the SQLite file
    $sqliteFile = "{$releasePath}/database/database.sqlite";
    $sharedSqlite = "{$basePath}/database/database.sqlite";

    // Create shared SQLite file if it doesn't exist
    if (! file_exists($sharedSqlite)) {
        touch($sharedSqlite);
    }

    // Remove release's SQLite file and symlink to shared
    if (file_exists($sqliteFile) && ! is_link($sqliteFile)) {
        unlink($sqliteFile);
    }

    if (! is_link($sqliteFile)) {
        symlink('../../../database/database.sqlite', $sqliteFile);
    }

    // migrations/, factories/, seeders/ stay as-is from the release ✓
}
```

### Result

```
releases/20260215_143000/
└── database/
    ├── database.sqlite → ../../../database/database.sqlite  ← Symlink (shared)
    ├── migrations/  ← From release (version-controlled)
    ├── factories/   ← From release (version-controlled)
    └── seeders/     ← From release (version-controlled)

shared database/
└── database.sqlite  ← The actual DB file
```

**Now migrations work**:
```bash
php artisan migrate
# Running migrations... ✓
```

## Prevention

### Zero-Downtime Deployment Database Rules

For Laravel deployments:

| Item | Should Be Shared? | Reason |
|------|-------------------|--------|
| `database/database.sqlite` | ✓ Yes | Persistent data across releases |
| `database/migrations/` | ✗ No | Version-controlled, changes per release |
| `database/factories/` | ✗ No | Version-controlled, changes per release |
| `database/seeders/` | ✗ No | Version-controlled, changes per release |

**General rule**: Only share **data**, never share **code**.

### What About Other Database Drivers?

**PostgreSQL / MySQL**:
- Database is external service, not in `database/` directory
- Only symlink `.env` (for connection credentials)
- Migrations work by default ✓

**SQLite**:
- Database file lives in `database/database.sqlite`
- Must symlink the file, not the directory
- Requires special handling (this solution)

### Warning Signs

- `php artisan migrate` reports "nothing to migrate"
- `ls database/migrations/` returns empty or "no such directory"
- Missing database tables but migrations claim success
- Fresh deploys have different table schema than previous releases

### Test Case

```php
/** @test */
public function it_preserves_migrations_directory_from_release(): void
{
    // Deploy with migrations
    $this->artisan('project:deploy', [
        'name' => 'test',
        '--clone' => 'org/repo-with-migrations',
    ]);

    // Verify migrations directory exists in current release
    $migrationsPath = $this->getProjectPath('test', 'current/database/migrations');
    $this->assertDirectoryExists($migrationsPath);

    // Verify migrations are accessible
    $migrations = glob("{$migrationsPath}/*.php");
    $this->assertNotEmpty($migrations);

    // Verify SQLite file is symlinked
    $sqlitePath = $this->getProjectPath('test', 'current/database/database.sqlite');
    $this->assertTrue(is_link($sqlitePath));
}
```

### Verification After Deploy

```bash
# Check migrations directory exists
ls current/database/migrations/
# Should list: 0001_01_01_000000_create_users_table.php, etc. ✓

# Check SQLite file is symlinked
ls -la current/database/database.sqlite
# Should show: database.sqlite -> ../../../database/database.sqlite ✓

# Run migrations
php artisan migrate
# Should run new migrations from current release ✓
```

## Alternative Approaches

### Approach 1: Copy Database to Each Release (❌ Data Loss)
```bash
cp -r database/ releases/20260215_143000/
```
**Why not**: Each release gets its own database copy. Data not shared. Sessions lost.

### Approach 2: PostgreSQL/MySQL (✓ Recommended for Production)
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
```
**Why yes**: External database service. Migrations and data naturally separated.

### Approach 3: Symlink Only SQLite File (✓ Recommended for SQLite)
```php
symlink('../../../database/database.sqlite',
        $releasePath.'/database/database.sqlite');
```
**Why yes**: Shares data, preserves migrations. This solution.

## Related

- **Issue**: platform11 deployment post-mortem (2026-02-15)
- **Deployment Pattern**: Zero-downtime release-based deployments
- **Laravel Structure**: `database/` directory contents
- **Orbit ProjectDeployCommand**: Fixed in v0.1.110

## Files Modified

- `packages/cli/app/Commands/ProjectDeployCommand.php` - New `ensureDatabaseStructure()` method

## Impact Timeline

- **Discovered**: 2026-02-15 during platform11.nl production deployment
- **Fixed**: v0.1.110 (same day)
- **Affected**: All SQLite-based projects using release deployments

## Recommendation

**For SQLite projects in zero-downtime deployments**:
- Always symlink the SQLite **file**, never the `database/` **directory**
- Keep migrations/factories/seeders in the release (version-controlled)
- Only share persistent data, never share code

**For production projects**:
- Consider PostgreSQL or MySQL instead of SQLite
- Removes the symlink complexity entirely
- Better performance and features
