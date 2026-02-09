<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Concerns\WithJsonOutput;
use App\Services\WgEasyService;
use HardImpact\Orbit\Core\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

final class GatewayClientsCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'gateway:clients {--json}';

    protected $description = 'List WireGuard clients with TLD mappings (runs on gateway)';

    public function handle(): int
    {
        $password = Setting::get('wg_easy_password');

        if ($password === null) {
            return $this->failWithMessage('No wg-easy password stored. Run: orbit gateway:set-password <password>');
        }

        $service = WgEasyService::forGateway('127.0.0.1', 51821, $password);
        $clients = $service->getClients();

        if ($clients === []) {
            return $this->failWithMessage('No clients found (or authentication failed).');
        }

        $tldMap = $this->buildTldMap();

        $enriched = array_map(function (array $client) use ($tldMap) {
            $ip = rtrim($client['ip'], '/');
            $ip = explode('/', $ip)[0];

            return [
                'name' => $client['name'],
                'ip' => $client['ip'],
                'enabled' => $client['enabled'],
                'online' => $this->isOnline($client['latestHandshakeAt']),
                'tld' => $tldMap[$ip] ?? null,
            ];
        }, $clients);

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess(['clients' => $enriched]);
        }

        foreach ($enriched as $client) {
            $dot = $client['online'] ? '<fg=green>●</>' : '<fg=gray>○</>';
            $nameColor = $client['online'] ? 'white' : 'gray';
            $disabled = $client['enabled'] ? '' : ' <fg=yellow>[disabled]</>';
            $tld = $client['tld'] ? " <fg=cyan>.{$client['tld']}</>" : '';

            $this->line("  {$dot}  <fg={$nameColor}>{$client['name']}</>  <fg=gray>{$client['ip']}</>{$tld}{$disabled}");
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function buildTldMap(): array
    {
        $containers = ['dnsmasq', 'orbit-dns'];
        $output = null;

        foreach ($containers as $container) {
            $result = Process::run("docker exec {$container} cat /etc/dnsmasq.d/dev-tlds.conf 2>/dev/null");
            if ($result->successful() && trim($result->output()) !== '') {
                $output = $result->output();
                break;
            }
        }

        if ($output === null) {
            foreach ($containers as $container) {
                $result = Process::run("docker exec {$container} sh -c 'cat /etc/dnsmasq.d/*.conf 2>/dev/null'");
                if ($result->successful() && trim($result->output()) !== '') {
                    $output = $result->output();
                    break;
                }
            }
        }

        if ($output === null) {
            return [];
        }

        $map = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^address=\/\.([^\/]+)\/([\d.]+)$/', $line, $matches)) {
                $map[$matches[2]] = $matches[1];
            }
        }

        return $map;
    }

    private function isOnline(?string $latestHandshakeAt): bool
    {
        if ($latestHandshakeAt === null) {
            return false;
        }

        try {
            return Carbon::parse($latestHandshakeAt)->diffInSeconds(now()) < 130;
        } catch (\Exception) {
            return false;
        }
    }

    private function failWithMessage(string $message): int
    {
        if ($this->wantsJson()) {
            return $this->outputJsonError($message);
        }

        $this->error($message);

        return self::FAILURE;
    }
}
