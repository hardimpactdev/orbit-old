<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $github_repo
 * @property string|null $production_domain
 * @property string|null $cloudflare_zone_id
 * @property string|null $cloudflare_zone_name
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<Deployment> $deployments
 */
class GatewayProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'github_repo',
        'production_domain',
        'cloudflare_zone_id',
        'cloudflare_zone_name',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function domainForNode(Node $node): ?string
    {
        if ($node->isProduction() && $this->production_domain) {
            return $this->production_domain;
        }

        return $node->tld ? "{$this->slug}.{$node->tld}" : null;
    }

    public function hasCloudflareZone(): bool
    {
        return $this->cloudflare_zone_id !== null;
    }
}
