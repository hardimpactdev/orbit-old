<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Support\HumanReadableFormatter;

trait WithHumanOutput
{
    private ?HumanReadableFormatter $formatter = null;

    protected function formatter(): HumanReadableFormatter
    {
        return $this->formatter ??= new HumanReadableFormatter($this->output);
    }

    /**
     * Auto-detect data shape and render.
     * List of arrays → borderless table, associative → key/value pairs.
     */
    protected function renderForHumans(array $data, ?string $title = null): void
    {
        $this->formatter()->render($data, $title);
    }
}
