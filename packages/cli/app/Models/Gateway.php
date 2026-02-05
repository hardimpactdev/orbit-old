<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $ip_address
 * @property string $ssh_user
 * @property string $status
 * @property \Carbon\Carbon|null $last_connected_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> where(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 * @method static static create(array $attributes = [])
 */
final class Gateway extends Model
{
    protected $fillable = [
        'name',
        'ip_address',
        'ssh_user',
        'status',
        'last_connected_at',
    ];

    protected $casts = [
        'last_connected_at' => 'datetime',
    ];
}
