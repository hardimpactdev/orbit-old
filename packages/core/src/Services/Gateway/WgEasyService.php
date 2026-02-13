<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services\Gateway;

use Illuminate\Support\Facades\Http;

/**
 * Service for interacting with WG Easy (WireGuard) API.
 */
final class WgEasyService
{
    private string $baseUrl;

    private string $password;

    public function __construct(
        string $host,
        int $port,
        string $password,
    ) {
        $this->baseUrl = "http://{$host}:{$port}";
        $this->password = $password;
    }

    public static function forGateway(string $host, int $port, string $password): self
    {
        return new self($host, $port, $password);
    }

    /**
     * Get the session cookie by logging in.
     */
    private function getSessionCookie(): ?string
    {
        try {
            $response = Http::asForm()->post("{$this->baseUrl}/api/session", [
                'password' => $this->password,
            ]);

            if ($response->successful()) {
                /** @var string|null $cookie */
                $cookie = $response->header('Set-Cookie');
                if ($cookie !== null && $cookie !== '') {
                    if (preg_match('/connect\.sid=([^;]+)/', $cookie, $matches)) {
                        return 'connect.sid='.$matches[1];
                    }
                }
            }
        } catch (\Exception) {
            // Fall through
        }

        return null;
    }

    /**
     * Create a new WireGuard client.
     *
     * @return array{id: string, ip: string, name: string}|null
     */
    public function createClient(string $name): ?array
    {
        $cookie = $this->getSessionCookie();
        if ($cookie === null) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Cookie' => $cookie,
            ])->post("{$this->baseUrl}/api/wireguard/client", [
                'name' => $name,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'id' => $data['id'] ?? $data['uuid'] ?? '',
                    'ip' => $data['address'] ?? $this->generateIp(),
                    'name' => $name,
                ];
            }
        } catch (\Exception) {
            // Fall through
        }

        return null;
    }

    /**
     * Get client configuration file content.
     */
    public function getClientConfig(string $name): ?string
    {
        $cookie = $this->getSessionCookie();
        if ($cookie === null) {
            return null;
        }

        $clientId = $this->getClientIdByName($name);
        if ($clientId === null) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Cookie' => $cookie,
            ])->get("{$this->baseUrl}/api/wireguard/client/{$clientId}/configuration");

            if ($response->successful()) {
                return $response->body();
            }
        } catch (\Exception) {
            // Fall through
        }

        return null;
    }

    /**
     * Get QR code for client (base64 PNG).
     */
    public function getClientQrCode(string $name): ?string
    {
        $cookie = $this->getSessionCookie();
        if ($cookie === null) {
            return null;
        }

        $clientId = $this->getClientIdByName($name);
        if ($clientId === null) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Cookie' => $cookie,
            ])->get("{$this->baseUrl}/api/wireguard/client/{$clientId}/qrcode");

            if ($response->successful()) {
                return $response->json('qrcode');
            }
        } catch (\Exception) {
            // Fall through
        }

        return null;
    }

    /**
     * Get all clients.
     *
     * @return array<int, array{id: string, name: string, ip: string, enabled: bool, latestHandshakeAt: string|null}>
     */
    public function getClients(): array
    {
        $cookie = $this->getSessionCookie();
        if ($cookie === null) {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'Cookie' => $cookie,
            ])->get("{$this->baseUrl}/api/wireguard/client");

            if ($response->successful()) {
                $clients = [];
                foreach ($response->json() as $client) {
                    $clients[] = [
                        'id' => $client['id'] ?? $client['uuid'] ?? '',
                        'name' => $client['name'] ?? '',
                        'ip' => $client['address'] ?? '',
                        'enabled' => $client['enabled'] ?? true,
                        'latestHandshakeAt' => $client['latestHandshakeAt'] ?? null,
                    ];
                }

                return $clients;
            }
        } catch (\Exception) {
            // Fall through
        }

        return [];
    }

    private function getClientIdByName(string $name): ?string
    {
        $clients = $this->getClients();
        foreach ($clients as $client) {
            if ($client['name'] === $name) {
                return $client['id'];
            }
        }

        return null;
    }

    private function generateIp(): string
    {
        return '10.8.0.'.random_int(2, 254);
    }
}
