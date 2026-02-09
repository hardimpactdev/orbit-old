<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Console\Commands;

use HardImpact\Orbit\Core\Enums\NodeStatus;
use HardImpact\Orbit\Core\Models\Node;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class OrbitInit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orbit:init {--name= : Display name for this node}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize local node for web mode';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (Node::where('is_default', true)->exists()) {
            $this->info('Node already exists. Skipping.');

            return Command::SUCCESS;
        }

        $configPath = rtrim(getenv('HOME'), '/').'/.config/orbit/config.json';
        $tld = 'test';

        if (File::exists($configPath)) {
            $config = json_decode(File::get($configPath), true);
            if (isset($config['tld'])) {
                $tld = ltrim($config['tld'], '.');
            }
        }

        $user = get_current_user();
        if (! $user) {
            $user = exec('whoami');
        }

        // Get node name: option > prompt > hostname
        $name = $this->option('name');
        if (! $name) {
            $hostname = gethostname() ?: 'Local';
            $name = $this->ask('Node display name', $hostname);
        }

        Node::create([
            'name' => $name,
            'host' => 'localhost',
            'user' => $user,
            'is_default' => true,
            'tld' => $tld,
            'status' => NodeStatus::Active,
        ]);

        $this->info("Node '{$name}' initialized with TLD: {$tld}");

        return Command::SUCCESS;
    }
}
