---
date: 2026-02-08
problem_type: database
component: core/migrations
severity: critical
symptoms:
  - "SQLSTATE[HY000]: General error: 1 no such index: nodes_is_local_index"
  - "SQLSTATE[HY000]: General error: 1 near FOREIGN: syntax error"
root_cause: SQLite doesn't rename indexes on table rename, and doesn't support DROP FOREIGN KEY
tags: [sqlite, migration, table-rename, index, foreign-key]
---

# SQLite Table Rename: Indexes and Foreign Keys Don't Follow

## Symptom

After renaming a table with `Schema::rename('environments', 'nodes')`, subsequent operations failed:

```
SQLSTATE[HY000]: General error: 1 no such index: nodes_is_local_index
```

And on non-SQLite databases, foreign key operations worked but SQLite threw:

```
SQLSTATE[HY000]: General error: 1 near "FOREIGN": syntax error
```

## Investigation

1. Attempted: `$table->dropIndex(['is_local'])` on the renamed table
   Result: Laravel generates `DROP INDEX nodes_is_local_index` but SQLite still stores the index under the original name `environments_is_local_index`

2. Attempted: `$table->dropForeign(['environment_id'])` on SQLite
   Result: SQLite doesn't support `ALTER TABLE ... DROP FOREIGN KEY` at all

## Root Cause

**Indexes:** SQLite stores index names literally. When you `Schema::rename('old', 'new')`, the table is renamed but all indexes keep their original names (prefixed with the old table name). Laravel's `dropIndex(['column'])` syntax generates the index name from the *current* table name, creating a mismatch.

**Foreign keys:** SQLite doesn't support dropping foreign keys as a separate operation. It only enforces FKs at insert/update time, and the only way to "drop" them is to recreate the table.

## Solution

```php
use Illuminate\Support\Facades\DB;

// 1. Use the ORIGINAL index name explicitly (not column-based shorthand)
Schema::table('nodes', function (Blueprint $table) {
    $table->dropIndex('environments_is_local_index'); // original name, not ['is_local']
    $table->dropColumn('is_local');
});

// 2. Guard FK drops with a driver check
if (DB::getDriverName() !== 'sqlite') {
    Schema::table('projects', function (Blueprint $table) {
        $table->dropForeign(['environment_id']);
    });
}

// 3. Column rename works on all drivers
Schema::table('projects', function (Blueprint $table) {
    $table->renameColumn('environment_id', 'node_id');
});

// 4. Re-add the FK (works on all drivers)
Schema::table('projects', function (Blueprint $table) {
    $table->foreign('node_id')->references('id')->on('nodes')->nullOnDelete();
});
```

## Prevention

- When renaming tables, always use explicit index names (string) not column-based shorthand (array)
- Wrap `dropForeign()` in `DB::getDriverName() !== 'sqlite'` guard
- Column renames and new FK additions work fine on SQLite without guards
- Test migrations with SQLite (Orchestra Testbench) before committing
- Remember: SQLite column renames via `renameColumn()` work since Laravel 11+ (uses `ALTER TABLE RENAME COLUMN`)

## Related

- Laravel docs: [Dropping Indexes](https://laravel.com/docs/migrations#dropping-indexes)
- SQLite limitations: No `ALTER TABLE DROP FOREIGN KEY`, no index rename on table rename
- Orchestra Testbench uses in-memory SQLite by default
