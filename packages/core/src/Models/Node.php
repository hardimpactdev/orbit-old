<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Models;

use HardImpact\Orbit\Core\Enums\NodeEnvironment;
use HardImpact\Orbit\Core\Enums\NodeStatus;
use HardImpact\Orbit\Core\Enums\NodeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $host
 * @property string $user
 * @property int $port
 * @property bool $is_active
 * @property bool $external_access
 * @property string|null $external_host
 * @property bool $is_default
 * @property string|null $tld
 * @property string|null $editor_scheme
 * @property string|null $cli_version
 * @property string|null $cli_path
 * @property \Carbon\Carbon|null $cli_checked_at
 * @property string|null $orchestrator_url
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $last_connected_at
 * @property NodeStatus $status
 * @property NodeType $node_type
 * @property NodeEnvironment $environment
 * @property array|null $provisioning_log
 * @property string|null $provisioning_error
 * @property int|null $provisioning_step
 * @property int|null $provisioning_total_steps
 * @property string|null $vpn_ip
 * @property int|null $gateway_id
 * @property \Carbon\Carbon|null $vpn_registered_at
 * @property string|null $custom_tld
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read bool $is_local
 * @property-read \Illuminate\Database\Eloquent\Collection<Deployment> $deployments
 */
class Node extends Model
{
    use HasFactory;

    protected $table = 'nodes';

    protected static function booted(): void
    {
        static::saving(function (Node $node): void {
            if ($node->host && ! in_array($node->host, ['127.0.0.1', 'localhost'], true)) {
                if (! filter_var($node->host, FILTER_VALIDATE_IP)
                    && ! filter_var($node->host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                    && ! preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/i', $node->host)) {
                    throw new \InvalidArgumentException("Invalid host: {$node->host}");
                }
            }

            if ($node->user && ! preg_match('/^[a-z_][a-z0-9_\-\.]*$/i', $node->user)) {
                throw new \InvalidArgumentException("Invalid SSH user: {$node->user}");
            }
        });
    }

    protected $fillable = [
        'name',
        'host',
        'user',
        'port',
        'is_active',
        'external_access',
        'external_host',
        'is_default',
        'tld',
        'editor_scheme',
        'cli_version',
        'cli_path',
        'cli_checked_at',
        'metadata',
        'last_connected_at',
        'status',
        'node_type',
        'environment',
        'provisioning_log',
        'provisioning_error',
        'provisioning_step',
        'provisioning_total_steps',
        'vpn_ip',
        'gateway_id',
        'vpn_registered_at',
        'custom_tld',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'external_access' => 'boolean',
        'is_default' => 'boolean',
        'status' => NodeStatus::class,
        'node_type' => NodeType::class,
        'environment' => NodeEnvironment::class,
        'metadata' => 'array',
        'last_connected_at' => 'datetime',
        'cli_checked_at' => 'datetime',
        'provisioning_log' => 'array',
        'vpn_registered_at' => 'datetime',
    ];

    protected $hidden = [
        'provisioning_log',
    ];

    protected $appends = ['is_local'];

    public function isLocal(): bool
    {
        return in_array($this->host, ['127.0.0.1', 'localhost']);
    }

    public function getIsLocalAttribute(): bool
    {
        return $this->isLocal();
    }

    public function isGateway(): bool
    {
        return $this->node_type === NodeType::Gateway;
    }

    public function isClient(): bool
    {
        return $this->node_type === NodeType::Client;
    }

    public function isLocalType(): bool
    {
        return $this->node_type === NodeType::Local;
    }

    public function hasCliCache(): bool
    {
        return $this->cli_version !== null
            && $this->cli_checked_at !== null
            && $this->cli_checked_at->diffInHours(now()) < 1;
    }

    public function updateCliCache(string $version, ?string $path = null): void
    {
        $this->update([
            'cli_version' => $version,
            'cli_path' => $path,
            'cli_checked_at' => now(),
        ]);
    }

    public function isProvisioning(): bool
    {
        return $this->status === NodeStatus::Provisioning;
    }

    public function isActive(): bool
    {
        return $this->status === NodeStatus::Active;
    }

    public function hasError(): bool
    {
        return $this->status === NodeStatus::Error;
    }

    public function getSshConnectionString(): string
    {
        if ($this->isLocal()) {
            return 'local';
        }

        $port = $this->port !== 22 ? "-p {$this->port}" : '';

        return trim("{$this->user}@{$this->host} {$port}");
    }

    public static function getSelf(): ?self
    {
        return static::where('is_default', true)->first();
    }

    /** @deprecated Use getSelf() instead */
    public static function getDefault(): ?self
    {
        return static::getSelf();
    }

    public static function getActive(): ?self
    {
        return app(\HardImpact\Orbit\Core\Services\NodeManager::class)->current();
    }

    public function setAsActive(): void
    {
        app(\HardImpact\Orbit\Core\Services\NodeManager::class)->setActive($this->id);
    }

    public function getEditor(): array
    {
        $scheme = $this->editor_scheme ?? Setting::get('editor_scheme', 'cursor');
        $options = Setting::getEditorOptions();

        return [
            'scheme' => $scheme,
            'name' => $options[$scheme] ?? 'Cursor',
        ];
    }

    public function hasVpn(): bool
    {
        return $this->vpn_ip !== null;
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function isProduction(): bool
    {
        return $this->environment === NodeEnvironment::Production;
    }

    public function isStaging(): bool
    {
        return $this->environment === NodeEnvironment::Staging;
    }

    public function isDevelopment(): bool
    {
        return $this->environment === NodeEnvironment::Development;
    }
}
