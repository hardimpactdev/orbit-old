<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

final class CloudflareZonesCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'cloudflare:zones {--json}';

    protected $description = 'List Cloudflare zones for a given API token (runs on gateway, reads token from stdin)';

    protected $hidden = true;

    public function handle(): int
    {
        $token = trim(fgets(STDIN) ?: '');

        if ($token === '') {
            return $this->outputJsonError('No API token provided via stdin.');
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->withOptions(['force_ip_resolve' => 'v4'])
                ->get('https://api.cloudflare.com/client/v4/zones');
        } catch (\Throwable $e) {
            return $this->outputJsonError("API request failed: {$e->getMessage()}");
        }

        $data = $response->json() ?? [];

        if (! ($data['success'] ?? false)) {
            $errors = $data['errors'] ?? [];
            $message = ! empty($errors) ? $errors[0]['message'] ?? 'Unknown API error' : 'Unknown API error';

            return $this->outputJsonError("Cloudflare API error: {$message}");
        }

        $zones = $data['result'] ?? [];

        if ($zones === []) {
            return $this->outputJsonError('Token is valid but no zones were returned.');
        }

        $simplified = array_map(fn (array $zone) => [
            'id' => $zone['id'],
            'name' => $zone['name'],
        ], $zones);

        return $this->outputJsonSuccess(['zones' => $simplified]);
    }
}
