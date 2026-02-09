<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Template;
use App\Templates\ClientNodeTemplate;
use App\Templates\GatewayTemplate;
use App\Templates\PhpDevTemplate;
use App\Templates\PhpProductionTemplate;

final class TemplateRegistry
{
    private const array ALIASES = [
        'development' => 'php-dev',
        'php' => 'php-dev',
    ];

    /** @var array<string, Template> */
    private array $templates = [];

    public function __construct()
    {
        $this->register(app(PhpDevTemplate::class));
        $this->register(app(PhpProductionTemplate::class));
        $this->register(app(GatewayTemplate::class));
        $this->register(app(ClientNodeTemplate::class));
    }

    public function register(Template $template): void
    {
        $this->templates[$template->name()] = $template;
    }

    public function get(string $name): Template
    {
        $name = self::ALIASES[$name] ?? $name;

        return $this->templates[$name] ?? throw new \InvalidArgumentException("Unknown template: {$name}");
    }

    public function has(string $name): bool
    {
        $resolved = self::ALIASES[$name] ?? $name;

        return isset($this->templates[$resolved]);
    }

    /**
     * @return array<string, Template>
     */
    public function all(): array
    {
        return $this->templates;
    }

    /**
     * @return array<string, Template>
     */
    public function forPlatform(string $osFamily): array
    {
        return array_filter($this->templates, fn (Template $t) => $t->supportsPlatform($osFamily));
    }
}
