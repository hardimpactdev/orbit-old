# Database

## Important: NativePHP uses TWO separate databases

NativePHP maintains two separate SQLite databases:

| Database                    | Connection         | Used By                        | Location          |
| --------------------------- | ------------------ | ------------------------------ | ----------------- |
| `database/database.sqlite`  | `sqlite` (default) | `php artisan` commands, tests  | Project directory |
| `database/nativephp.sqlite` | `nativephp`        | Running desktop app (dev mode) | Project directory |

**Running Migrations:**

The `nativephp` database connection is only configured when running inside the NativePHP app context. You cannot use `php artisan migrate --database=nativephp` from the terminal.

```bash
# Standard migration (only affects database/database.sqlite)
php artisan migrate

# For the NativePHP database, you have two options:

# Option 1: Restart the app (NativePHP runs migrations on startup)
# Stop and restart: php artisan native:serve

# Option 2: Run migrations directly on the SQLite file
php artisan migrate --database=sqlite --database-path=database/nativephp.sqlite
```

**If Option 2 doesn't work**, you can create a temporary database connection in `config/database.php`:

```php
'nativephp_dev' => [
    'driver' => 'sqlite',
    'database' => database_path('nativephp.sqlite'),
],
```

Then run: `php artisan migrate --database=nativephp_dev`

**Common Issues:**

- "No such column" or "No such table" errors in the app -> The NativePHP database needs migration
- `php artisan migrate --database=nativephp` fails with "connection not configured" -> This is expected, use methods above
- Data missing in app but exists in tests -> The two databases are out of sync

**When to restart the app:**

- After adding new migrations (NativePHP runs them on startup)
- After changing `.env` configuration
- After modifying NativePHP config files
