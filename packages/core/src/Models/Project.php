<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Models;

use HardImpact\Orbit\Core\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $node_id
 * @property string $name
 * @property string|null $display_name
 * @property string $slug
 * @property string|null $path
 * @property string|null $php_version
 * @property string|null $github_repo
 * @property string|null $project_type
 * @property bool $has_public_folder
 * @property string|null $domain
 * @property string|null $url
 * @property ProjectStatus|null $status
 * @property string|null $error_message
 * @property string|null $job_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Project extends Model
{
    protected $casts = [
        'has_public_folder' => 'boolean',
        'status' => ProjectStatus::class,
    ];

    protected $fillable = [
        'node_id',
        'name',
        'display_name',
        'slug',
        'path',
        'php_version',
        'github_repo',
        'project_type',
        'has_public_folder',
        'domain',
        'url',
        'status',
        'error_message',
        'job_id',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function isProvisioning(): bool
    {
        return $this->status?->isProvisioning() ?? false;
    }

    public function isReady(): bool
    {
        return $this->status === ProjectStatus::Ready;
    }

    public function isFailed(): bool
    {
        return $this->status === ProjectStatus::Failed;
    }
}
