<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string|null $value
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::find($key);

        return $setting !== null ? $setting->value : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    public static function getEditor(): array
    {
        return [
            'scheme' => static::get('editor_scheme', 'cursor'),
            'name' => static::get('editor_name', 'Cursor'),
        ];
    }

    public static function getEditorOptions(): array
    {
        return [
            'cursor' => 'Cursor',
            'vscode' => 'VS Code',
            'vscode-insiders' => 'VS Code Insiders',
            'windsurf' => 'Windsurf',
            'antigravity' => 'Antigravity',
            'zed' => 'Zed',
        ];
    }

    public static function getTerminal(): string
    {
        return static::get('terminal', 'Terminal');
    }

    public static function getTerminalOptions(): array
    {
        return [
            'Terminal' => 'Terminal',
            'iTerm' => 'iTerm2',
            'Ghostty' => 'Ghostty',
            'Warp' => 'Warp',
            'kitty' => 'Kitty',
            'Alacritty' => 'Alacritty',
            'Hyper' => 'Hyper',
        ];
    }

    public static function getSshPublicKey(): ?string
    {
        return static::get('ssh_public_key');
    }

    public static function setSshPublicKey(string $key): void
    {
        static::set('ssh_public_key', trim($key));
    }

    /** @deprecated Use SshKey::getAvailableLocalKeys() instead */
    public static function getAvailableSshKeys(): array
    {
        return SshKey::getAvailableLocalKeys();
    }
}
