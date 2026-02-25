<?php

declare(strict_types=1);

namespace App\Commands\Service;

use App\Concerns\WithHumanOutput;
use App\Concerns\WithJsonOutput;
use App\Services\ServiceManager;
use App\Services\ServiceTemplateLoader;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

final class ServiceInfoCommand extends Command
{
    use WithHumanOutput;
    use WithJsonOutput;

    protected $signature = 'service:info 
                            {service : Service name}
                            {--json : Output as JSON}';

    protected $description = 'Show detailed information about a service';

    public function handle(ServiceManager $serviceManager, ServiceTemplateLoader $templateLoader): int
    {
        $serviceName = $this->argument('service');

        // Get service configuration
        $config = $serviceManager->getService($serviceName);

        // Get template information if available
        $template = null;
        $templateExists = $templateLoader->exists($serviceName);

        if ($templateExists) {
            try {
                $template = $templateLoader->load($serviceName);
            } catch (RuntimeException) {
                // Template exists but couldn't be loaded
            }
        }

        if ($this->wantsJson()) {
            $data = [
                'service' => $serviceName,
                'configured' => $config !== null,
            ];

            if ($config !== null) {
                $data['configuration'] = $config;
            }

            if ($template !== null) {
                $data['template'] = [
                    'name' => $template->name,
                    'label' => $template->label,
                    'description' => $template->description,
                    'category' => $template->category,
                    'versions' => $template->versions,
                    'configSchema' => $template->configSchema,
                    'dependsOn' => $template->dependsOn,
                ];
            }

            return $this->outputJsonSuccess($data);
        }

        // Human-readable output
        $this->newLine();

        if ($config === null && $template === null) {
            $this->error("  Service '{$serviceName}' not found");
            $this->line("  <fg=gray>Use 'orbit service:list --available' to see available services</>");
            $this->newLine();

            return self::FAILURE;
        }

        // Show template information
        if ($template !== null) {
            $this->line("  <fg=cyan>{$template->label}</> ({$template->name})");
            $this->line("  {$template->description}");

            $templateInfo = [
                'category' => $template->category,
                'available_versions' => implode(', ', $template->versions),
            ];

            if (! empty($template->dependsOn)) {
                $templateInfo['dependencies'] = implode(', ', $template->dependsOn);
            }

            $this->renderForHumans($templateInfo);
        }

        // Show current configuration
        if ($config !== null) {
            $this->renderForHumans($config, 'Configuration');
        } else {
            $this->line('  <fg=yellow>Not configured</>');
            $this->line("  <fg=gray>Run 'orbit service:enable {$serviceName}' to enable this service</>");
        }

        // Show configuration schema if available
        if ($template !== null && ! empty($template->configSchema['properties'])) {
            $schemaData = [];
            foreach ($template->configSchema['properties'] as $key => $schema) {
                $type = $schema['type'] ?? 'string';
                $default = isset($schema['default']) ? " (default: {$schema['default']})" : '';
                $required = in_array($key, $template->configSchema['required'] ?? [], true) ? ' *' : '';

                $schemaData[$key] = $type.$default.$required;
            }

            $this->renderForHumans($schemaData, 'Available Options');
            $this->line('  <fg=gray>Use \'orbit service:configure '.$serviceName.' --set key=value\' to configure</>');
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
