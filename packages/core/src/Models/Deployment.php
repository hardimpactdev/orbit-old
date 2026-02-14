<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Models;

use HardImpact\Orbit\Core\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $node_id
 * @property int|null $gateway_project_id
 * @property string $project_slug
 * @property string $project_name
 * @property string|null $github_repo
 * @property string|null $domain
 * @property string|null $url
 * @property string|null $php_version
 * @property DeploymentStatus $status
 * @property string|null $error_message
 * @property string|null $cloudflare_record_id
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Node $node
 * @property-read GatewayProject|null $gatewayProject
 */
class Deployment extends Model
{
    protected $fillable = [
        'node_id',
        'gateway_project_id',
        'project_slug',
        'project_name',
        'github_repo',
        'domain',
        'url',
        'php_version',
        'status',
        'error_message',
        'cloudflare_record_id',
        'metadata',
    ];

    protected $casts = [
        'status' => DeploymentStatus::class,
        'metadata' => 'array',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function gatewayProject(): BelongsTo
    {
        return $this->belongsTo(GatewayProject::class);
    }

    public function isActive(): bool
    {
        return $this->status === DeploymentStatus::Active;
    }

    public function isFailed(): bool
    {
        return $this->status === DeploymentStatus::Failed;
    }

    public function isRemoved(): bool
    {
        return $this->status === DeploymentStatus::Removed;
    }

    public function hasCloudflareRecord(): bool
    {
        return $this->cloudflare_record_id !== null;
    }
}
