<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property string $public_key
 * @property bool $is_default
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class SshKey extends Model
{
    protected $fillable = ['name', 'public_key', 'is_default'];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first()
            ?? static::first();
    }

    public function setAsDefault(): void
    {
        DB::transaction(function () {
            static::where('is_default', true)->update(['is_default' => false]);
            $this->update(['is_default' => true]);
        });
    }

    public function getKeyTypeAttribute(): string
    {
        $parts = explode(' ', $this->public_key);

        return $parts[0];
    }

    public static function getAvailableLocalKeys(): array
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? $_ENV['HOME'] ?? null);
        if (! $home) {
            return [];
        }

        $sshDir = $home.'/.ssh';
        if (! is_dir($sshDir)) {
            return [];
        }

        $keys = [];

        $files = glob($sshDir.'/*.pub');
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content && str_starts_with($content, 'ssh-')) {
                $keys[basename($file)] = [
                    'path' => $file,
                    'content' => trim($content),
                    'type' => explode(' ', $content)[0],
                ];
            }
        }

        return $keys;
    }
}
