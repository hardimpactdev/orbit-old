---
date: 2026-02-08
problem_type: refactoring
component: cli/templates
severity: minor
symptoms:
  - "Template name 'development' is misleading for a PHP stack template"
root_cause: Original naming didn't anticipate future template types
tags: [rename, migration, backward-compat, cli]
---

# Lazy Migration Pattern for CLI Concept Renames

## Context

Renaming the "development" template to "php" required migrating stored references across three persistence layers without breaking existing installs.

## Migration Surfaces

When renaming a concept in the CLI, check **all** persistence layers:

| Surface | File/Location | Migration Strategy |
|---------|--------------|-------------------|
| config.json on disk | `~/.config/orbit/config.json` | Auto-migrate in `ConfigManager::load()` |
| TemplateRegistry lookup | In-memory registry | Alias constant `ALIASES = ['old' => 'new']` |
| SQLite Setting store | `installed_template` key | Migrate on read in consuming code |

## Pattern: Lazy Migration on Read

Each layer migrates the old value to the new one on first read, no manual migration step needed:

```php
// ConfigManager::load() — migrate config.json
if (($this->config['template'] ?? null) === 'development') {
    $this->config['template'] = 'php';
    $this->save();
}

// TemplateRegistry::get() — alias lookup
private const array ALIASES = ['development' => 'php'];

public function get(string $name): Template
{
    $name = self::ALIASES[$name] ?? $name;
    return $this->templates[$name] ?? throw new \InvalidArgumentException("Unknown template: {$name}");
}

// InstallCommand::confirmReinstall() — migrate DB setting
if ($installedTemplate === 'development') {
    $installedTemplate = 'php';
    Setting::set('installed_template', 'php');
}
```

## Key Principle

CLI tools should never require users to run a migration command. All renames should self-heal on next invocation.

## Checklist for Future Renames

1. Rename file + class
2. Update all direct references in code
3. Add alias in registry/lookup layer
4. Add auto-migration in config file loader
5. Add auto-migration for any database-stored values
6. Update config stubs/templates
7. Update tests (add alias test)
8. Grep for orphaned string references
