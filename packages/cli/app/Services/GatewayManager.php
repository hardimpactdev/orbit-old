<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Manage gateway server configurations.
 *
 * Gateways are stored in config.json under the 'gateways' key.
 */
final class GatewayManager
{
    public function __construct(
        private ConfigManager $configManager,
    ) {}

    /**
     * Get all configured gateways.
     *
     * @return array<int, array{id: string, name: string, ip: string, subnet: string}>
     */
    public function all(): array
    {
        return $this->configManager->get('gateways', []);
    }

    /**
     * Check if any gateways are configured.
     */
    public function hasAny(): bool
    {
        return count($this->all()) > 0;
    }

    /**
     * Get a gateway by ID.
     *
     * @return array{id: string, name: string, ip: string, subnet: string}|null
     */
    public function get(string $id): ?array
    {
        foreach ($this->all() as $gateway) {
            if ($gateway['id'] === $id) {
                return $gateway;
            }
        }

        return null;
    }

    /**
     * Add a new gateway.
     *
     * ID is generated as snake_case from the name.
     *
     * @return array{id: string, name: string, ip: string, subnet: string}
     */
    public function add(string $name, string $ip, string $subnet): array
    {
        $gateway = [
            'id' => $this->generateId($name),
            'name' => $name,
            'ip' => $ip,
            'subnet' => $subnet,
            'created_at' => now()->toIso8601String(),
        ];

        $gateways = $this->all();
        $gateways[] = $gateway;
        $this->configManager->set('gateways', $gateways);

        return $gateway;
    }

    /**
     * Generate kebab-case ID from name.
     */
    public function generateId(string $name): string
    {
        // Convert to lowercase
        $id = strtolower($name);
        // Replace spaces and special chars with hyphens
        $id = preg_replace('/[^a-z0-9]+/', '-', $id);
        // Remove leading/trailing hyphens
        $id = trim($id, '-');
        // Collapse multiple hyphens
        $id = preg_replace('/-+/', '-', $id);

        return $id;
    }

    /**
     * Remove a gateway by ID.
     */
    public function remove(string $id): bool
    {
        $gateways = $this->all();
        $filtered = array_filter($gateways, fn ($g) => $g['id'] !== $id);

        if (count($filtered) === count($gateways)) {
            return false; // Nothing removed
        }

        $this->configManager->set('gateways', array_values($filtered));

        return true;
    }

    /**
     * Get gateway options for select prompts.
     *
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        $options = [];
        foreach ($this->all() as $gateway) {
            $options[$gateway['id']] = "{$gateway['name']} ({$gateway['ip']})";
        }

        return $options;
    }

    /**
     * Check if a gateway ID already exists.
     */
    public function idExists(string $id): bool
    {
        foreach ($this->all() as $gateway) {
            if ($gateway['id'] === $id) {
                return true;
            }
        }

        return false;
    }
}
