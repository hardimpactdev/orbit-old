<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

final class HumanReadableFormatter
{
    /** @var array<string, string> */
    private const ABBREVIATIONS = [
        'id' => 'ID',
        'ip' => 'IP',
        'tld' => 'TLD',
        'vpn' => 'VPN',
        'url' => 'URL',
        'php' => 'PHP',
        'fpm' => 'FPM',
        'ssl' => 'SSL',
        'dns' => 'DNS',
        'cpu' => 'CPU',
        'ram' => 'RAM',
        'ssh' => 'SSH',
        'api' => 'API',
        'cli' => 'CLI',
        'os' => 'OS',
        'db' => 'DB',
    ];

    private readonly int $terminalWidth;

    public function __construct(
        private readonly OutputInterface $output,
        ?int $terminalWidth = null,
    ) {
        $this->terminalWidth = $terminalWidth ?? (new Terminal)->getWidth();
    }

    /**
     * Auto-detect data shape and render appropriately.
     *
     * - Numeric-keyed list of arrays → borderless table
     * - Associative array → aligned key/value pairs
     */
    public function render(array $data, ?string $title = null): void
    {
        if ($title !== null) {
            $this->output->writeln('');
            $this->output->writeln("  <fg=cyan>{$title}</>");
            $this->output->writeln('');
        }

        if ($data === []) {
            $this->output->writeln('  <fg=gray>No data</>');
            $this->output->writeln('');

            return;
        }

        if ($this->isList($data)) {
            $this->renderTable($data);
        } else {
            $this->renderKeyValue($data);
        }

        $this->output->writeln('');
    }

    /**
     * Render an associative array as aligned key/value pairs.
     */
    public function renderKeyValue(array $data, int $indent = 2): void
    {
        if ($data === []) {
            return;
        }

        $rows = [];
        foreach ($data as $key => $value) {
            $rows[] = [
                'key' => $this->humanizeKey((string) $key),
                'value' => $this->formatValue($value),
            ];
        }

        $maxKeyLength = max(array_map(fn ($row) => mb_strlen($row['key']), $rows));
        $pad = str_repeat(' ', $indent);

        foreach ($rows as $row) {
            $paddedKey = str_pad($row['key'], $maxKeyLength);
            $this->output->writeln("{$pad}<fg=gray>{$paddedKey}</>  {$row['value']}");
        }
    }

    /**
     * Render a list of associative arrays as a borderless table.
     * Falls back to vertical key/value cards when the table is too wide.
     */
    public function renderTable(array $data, int $indent = 2): void
    {
        if ($data === [] || ! is_array($data[0])) {
            return;
        }

        $headers = array_keys($data[0]);
        $humanHeaders = array_map(fn ($h) => $this->humanizeKey((string) $h), $headers);

        // Calculate column widths
        $widths = [];
        foreach ($humanHeaders as $i => $header) {
            $widths[$i] = mb_strlen($header);
        }

        $formattedRows = [];
        foreach ($data as $row) {
            $formattedRow = [];
            foreach ($headers as $i => $header) {
                $formatted = $this->formatValue($row[$header] ?? null);
                $formattedRow[] = $formatted;
                $stripped = $this->stripFormatting($formatted);
                $widths[$i] = max($widths[$i], mb_strlen($stripped));
            }
            $formattedRows[] = $formattedRow;
        }

        // Check if table fits in terminal
        $totalWidth = $indent + array_sum($widths) + (count($widths) * 2);

        if ($totalWidth > $this->terminalWidth) {
            $this->renderVerticalCards($data, $indent);

            return;
        }

        $pad = str_repeat(' ', $indent);

        // Header line
        $headerLine = $pad;
        foreach ($humanHeaders as $i => $header) {
            $headerLine .= str_pad($header, $widths[$i] + 2);
        }
        $this->output->writeln("<fg=cyan>{$headerLine}</>");

        // Data rows
        foreach ($formattedRows as $row) {
            $line = $pad;
            foreach ($row as $i => $value) {
                $stripped = $this->stripFormatting($value);
                $padding = $widths[$i] - mb_strlen($stripped);
                $line .= $value.str_repeat(' ', max(0, $padding) + 2);
            }
            $this->output->writeln($line);
        }
    }

    /**
     * Render each row as a vertical key/value card (fallback for narrow terminals).
     */
    private function renderVerticalCards(array $data, int $indent): void
    {
        foreach ($data as $i => $row) {
            if ($i > 0) {
                $this->output->writeln('');
            }

            // Use raw keys so renderKeyValue handles humanization
            $this->renderKeyValue($row, $indent);
        }
    }

    /**
     * Convert a snake_case or kebab-case key to Title Case.
     *
     * Known abbreviations (ID, IP, TLD, etc.) stay uppercased.
     */
    public function humanizeKey(string $key): string
    {
        $words = preg_split('/[_\-]/', $key);

        return implode(' ', array_map(function (string $word): string {
            $lower = strtolower($word);

            return self::ABBREVIATIONS[$lower] ?? ucfirst($lower);
        }, $words));
    }

    /**
     * Format a value for human display with appropriate coloring.
     */
    public function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '<fg=gray>-</>';
        }

        if (is_bool($value)) {
            return $value ? '<fg=green>Yes</>' : '<fg=gray>No</>';
        }

        if (is_array($value)) {
            if ($value === []) {
                return '<fg=gray>-</>';
            }

            return implode(', ', array_map(fn ($v) => $this->formatValue($v), $value));
        }

        $string = (string) $value;

        // Status-like values
        $colored = match (strtolower($string)) {
            'running', 'active', 'online', 'healthy', 'enabled', 'success', 'connected' => "<fg=green>{$string}</>",
            'stopped', 'offline', 'unhealthy', 'disabled', 'failed', 'error', 'removed' => "<fg=red>{$string}</>",
            'starting', 'pending', 'warning', 'provisioning', 'queued' => "<fg=yellow>{$string}</>",
            default => null,
        };

        if ($colored !== null) {
            return $colored;
        }

        // URLs
        if (preg_match('#^https?://#', $string)) {
            return "<href={$string}>{$string}</>";
        }

        return $string;
    }

    /**
     * Check if data is a numeric-keyed list of arrays (table-shaped).
     */
    private function isList(array $data): bool
    {
        return array_is_list($data) && is_array($data[0]);
    }

    /**
     * Strip Symfony console formatting tags for width calculation.
     */
    private function stripFormatting(string $text): string
    {
        return (string) preg_replace('/<[^>]*>/', '', $text);
    }
}
