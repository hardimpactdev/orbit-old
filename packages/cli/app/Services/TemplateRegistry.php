<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Template;
use App\Templates\DevelopmentTemplate;

final class TemplateRegistry
{
    /** @var array<string, Template> */
    private array $templates = [];

    public function __construct()
    {
        $this->register(new DevelopmentTemplate);
    }

    public function register(Template $template): void
    {
        $this->templates[$template->name()] = $template;
    }

    public function get(string $name): Template
    {
        return $this->templates[$name] ?? throw new \InvalidArgumentException("Unknown template: {$name}");
    }

    public function has(string $name): bool
    {
        return isset($this->templates[$name]);
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
