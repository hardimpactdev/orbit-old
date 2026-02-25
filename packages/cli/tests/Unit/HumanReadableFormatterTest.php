<?php

use App\Support\HumanReadableFormatter;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    $this->output = new BufferedOutput;
    $this->formatter = new HumanReadableFormatter($this->output);
});

describe('humanizeKey', function () {
    it('converts snake_case to Title Case', function () {
        expect($this->formatter->humanizeKey('first_name'))->toBe('First Name');
        expect($this->formatter->humanizeKey('created_at'))->toBe('Created At');
        expect($this->formatter->humanizeKey('has_public_folder'))->toBe('Has Public Folder');
    });

    it('converts kebab-case to Title Case', function () {
        expect($this->formatter->humanizeKey('php-version'))->toBe('PHP Version');
        expect($this->formatter->humanizeKey('node-type'))->toBe('Node Type');
    });

    it('preserves known abbreviations', function () {
        expect($this->formatter->humanizeKey('vpn_ip'))->toBe('VPN IP');
        expect($this->formatter->humanizeKey('tld'))->toBe('TLD');
        expect($this->formatter->humanizeKey('cli_version'))->toBe('CLI Version');
        expect($this->formatter->humanizeKey('dns_id'))->toBe('DNS ID');
        expect($this->formatter->humanizeKey('ssh'))->toBe('SSH');
        expect($this->formatter->humanizeKey('api_url'))->toBe('API URL');
        expect($this->formatter->humanizeKey('db'))->toBe('DB');
        expect($this->formatter->humanizeKey('os'))->toBe('OS');
    });

    it('handles single words', function () {
        expect($this->formatter->humanizeKey('name'))->toBe('Name');
        expect($this->formatter->humanizeKey('status'))->toBe('Status');
    });
});

describe('formatValue', function () {
    it('formats null as gray dash', function () {
        expect($this->formatter->formatValue(null))->toBe('<fg=gray>-</>');
    });

    it('formats booleans as Yes/No', function () {
        expect($this->formatter->formatValue(true))->toBe('<fg=green>Yes</>');
        expect($this->formatter->formatValue(false))->toBe('<fg=gray>No</>');
    });

    it('formats empty arrays as gray dash', function () {
        expect($this->formatter->formatValue([]))->toBe('<fg=gray>-</>');
    });

    it('formats arrays as comma-separated values', function () {
        expect($this->formatter->formatValue(['a', 'b', 'c']))->toBe('a, b, c');
    });

    it('colors positive status values green', function () {
        foreach (['running', 'active', 'online', 'healthy', 'enabled', 'success', 'connected'] as $status) {
            expect($this->formatter->formatValue($status))->toBe("<fg=green>{$status}</>");
        }
    });

    it('colors negative status values red', function () {
        foreach (['stopped', 'offline', 'unhealthy', 'disabled', 'failed', 'error', 'removed'] as $status) {
            expect($this->formatter->formatValue($status))->toBe("<fg=red>{$status}</>");
        }
    });

    it('colors transitional status values yellow', function () {
        foreach (['starting', 'pending', 'warning', 'provisioning', 'queued'] as $status) {
            expect($this->formatter->formatValue($status))->toBe("<fg=yellow>{$status}</>");
        }
    });

    it('passes plain strings through unchanged', function () {
        expect($this->formatter->formatValue('hello'))->toBe('hello');
        expect($this->formatter->formatValue('8.4'))->toBe('8.4');
        expect($this->formatter->formatValue('/path/to/project'))->toBe('/path/to/project');
    });

    it('formats integers and floats as strings', function () {
        expect($this->formatter->formatValue(42))->toBe('42');
        expect($this->formatter->formatValue(3.14))->toBe('3.14');
    });

    it('makes URLs clickable', function () {
        expect($this->formatter->formatValue('https://example.com'))
            ->toBe('<href=https://example.com>https://example.com</>');
        expect($this->formatter->formatValue('http://localhost:8080'))
            ->toBe('<href=http://localhost:8080>http://localhost:8080</>');
    });
});

describe('render (auto-detect)', function () {
    it('renders empty data with message', function () {
        $this->formatter->render([], 'Test');
        $output = $this->output->fetch();

        expect($output)->toContain('No data');
    });

    it('renders associative array as key/value pairs', function () {
        $this->formatter->render([
            'name' => 'myproject',
            'tld' => 'bear',
            'is_active' => true,
        ], 'Project Info');

        $output = $this->output->fetch();

        expect($output)
            ->toContain('Project Info')
            ->toContain('Name')
            ->toContain('myproject')
            ->toContain('TLD')
            ->toContain('bear')
            ->toContain('Is Active')
            ->toContain('Yes');
    });

    it('renders list of arrays as table', function () {
        $this->formatter->render([
            ['name' => 'proj1', 'status' => 'running', 'php_version' => '8.4'],
            ['name' => 'proj2', 'status' => 'stopped', 'php_version' => '8.3'],
        ], 'Projects');

        $output = $this->output->fetch();

        expect($output)
            ->toContain('Projects')
            ->toContain('Name')
            ->toContain('Status')
            ->toContain('PHP Version')
            ->toContain('proj1')
            ->toContain('proj2');
    });
});

describe('renderKeyValue', function () {
    it('aligns keys with padding', function () {
        $this->formatter->renderKeyValue([
            'name' => 'test',
            'php_version' => '8.4',
        ]);

        $output = $this->output->fetch();
        $lines = array_filter(explode("\n", $output), fn ($l) => trim($l) !== '');

        // Both lines should have the same key column width
        expect(count($lines))->toBe(2);
        expect($lines[0])->toContain('Name');
        expect($lines[1])->toContain('PHP Version');
    });

    it('respects indent parameter', function () {
        $this->formatter->renderKeyValue(['name' => 'test'], indent: 4);

        $output = $this->output->fetch();
        // Should start with 4 spaces
        expect($output)->toMatch('/^    /');
    });
});

describe('renderTable', function () {
    it('renders header row with humanized keys', function () {
        $this->formatter->renderTable([
            ['node_type' => 'client', 'vpn_ip' => '10.8.0.2'],
        ]);

        $output = $this->output->fetch();

        expect($output)
            ->toContain('Node Type')
            ->toContain('VPN IP')
            ->toContain('client')
            ->toContain('10.8.0.2');
    });

    it('handles missing keys gracefully', function () {
        $this->formatter->renderTable([
            ['name' => 'a', 'extra' => 'val'],
            ['name' => 'b'],
        ]);

        $output = $this->output->fetch();

        expect($output)->toContain('a')->toContain('b');
    });

    it('auto-formats values in cells', function () {
        $this->formatter->renderTable([
            ['name' => 'svc', 'status' => 'running', 'is_active' => true],
        ]);

        $output = $this->output->fetch();

        // Non-decorated output strips tags, but values should still be present
        expect($output)
            ->toContain('svc')
            ->toContain('running')
            ->toContain('Yes');
    });

    it('skips empty data', function () {
        $this->formatter->renderTable([]);
        expect($this->output->fetch())->toBe('');
    });

    it('falls back to vertical cards on narrow terminals', function () {
        $narrowOutput = new BufferedOutput;
        $narrowFormatter = new HumanReadableFormatter($narrowOutput, terminalWidth: 30);

        $narrowFormatter->renderTable([
            ['name' => 'my-project', 'status' => 'running', 'php_version' => '8.4'],
            ['name' => 'another', 'status' => 'stopped', 'php_version' => '8.3'],
        ]);

        $output = $narrowOutput->fetch();

        // Should render as key/value cards (one per row) instead of a table
        // Key/value format has "Key  value" on separate lines
        expect($output)
            ->toContain('Name')
            ->toContain('my-project')
            ->toContain('another')
            ->toContain('Status')
            ->toContain('PHP Version');
    });

    it('uses table layout when terminal is wide enough', function () {
        $wideOutput = new BufferedOutput;
        $wideFormatter = new HumanReadableFormatter($wideOutput, terminalWidth: 200);

        $wideFormatter->renderTable([
            ['name' => 'proj', 'status' => 'running'],
        ]);

        $output = $wideOutput->fetch();
        $lines = array_filter(explode("\n", $output), fn ($l) => trim($l) !== '');

        // Table layout: header + 1 data row = 2 lines
        expect(count($lines))->toBe(2);
    });
});
