<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Models;

use HardImpact\Orbit\Core\Enums\TrackedJobStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $name
 * @property TrackedJobStatus $status
 * @property string|null $output
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class TrackedJob extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'status',
        'output',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'status' => TrackedJobStatus::class,
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    public function isPending(): bool
    {
        return $this->status === TrackedJobStatus::Pending;
    }

    public function isProcessing(): bool
    {
        return $this->status === TrackedJobStatus::Processing;
    }

    public function isCompleted(): bool
    {
        return $this->status === TrackedJobStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === TrackedJobStatus::Failed;
    }
}
