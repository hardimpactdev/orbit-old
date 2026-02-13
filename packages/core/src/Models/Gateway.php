<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $ip_address
 * @property string $ssh_user
 * @property string $status
 * @property string $subnet
 * @property string|null $wg_password
 * @property int $wg_api_port
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
        'subnet',
        'wg_password',
        'wg_api_port',
        'last_connected_at',
    ];

    protected $casts = [
        'last_connected_at' => 'datetime',
        'wg_api_port' => 'integer',
    ];

    /**
     * Get the VPN gateway IP from the subnet.
     * For subnet 10.6.0.0/24, returns 10.6.0.1
     */
    public function getVpnGatewayIp(): string
    {
        $parts = explode('/', $this->subnet);
        $network = $parts[0];
        $octets = explode('.', $network);

        $octets[3] = '1';

        return implode('.', $octets);
    }
}
