<?php

use App\Actions\Install\Linux;
use App\Actions\Install\Mac;
use App\Actions\Install\Shared;
use App\Components\CaddyComponent;
use App\Components\DnsComponent;
use App\Components\DockerComponent;
use App\Components\PhpComponent;
use App\Services\TemplateRegistry;
use App\Templates\DevelopmentTemplate;

describe('DevelopmentTemplate', function () {
    beforeEach(function () {
        $this->template = new DevelopmentTemplate(
            new DockerComponent,
            new PhpComponent,
            new CaddyComponent,
            new DnsComponent,
        );
    });

    it('returns correct mac step count', function () {
        expect($this->template->installSteps('Darwin'))->toHaveCount(19);
    });

    it('returns correct linux step count', function () {
        expect($this->template->installSteps('Linux'))->toHaveCount(19);
    });

    it('starts mac steps with CheckPrerequisites', function () {
        $steps = $this->template->installSteps('Darwin');

        expect($steps[0]['action'])->toBe(Mac\CheckPrerequisites::class);
    });

    it('starts linux steps with CheckPrerequisites', function () {
        $steps = $this->template->installSteps('Linux');

        expect($steps[0]['action'])->toBe(Linux\CheckPrerequisites::class);
    });

    it('ends mac steps with HealthCheck', function () {
        $steps = $this->template->installSteps('Darwin');

        expect($steps[count($steps) - 1]['action'])->toBe(Shared\HealthCheck::class);
    });

    it('ends linux steps with HealthCheck', function () {
        $steps = $this->template->installSteps('Linux');

        expect($steps[count($steps) - 1]['action'])->toBe(Shared\HealthCheck::class);
    });

    it('includes OrbStack for mac and Docker for linux', function () {
        $macActions = collect($this->template->installSteps('Darwin'))->pluck('action');
        $linuxActions = collect($this->template->installSteps('Linux'))->pluck('action');

        expect($macActions)->toContain(Mac\InstallOrbStack::class);
        expect($macActions)->not->toContain(Linux\InstallDocker::class);

        expect($linuxActions)->toContain(Linux\InstallDocker::class);
        expect($linuxActions)->not->toContain(Mac\InstallOrbStack::class);
    });

    it('includes all shared actions for both platforms', function () {
        foreach (['Darwin', 'Linux'] as $platform) {
            $actions = collect($this->template->installSteps($platform))->pluck('action');

            expect($actions)->toContain(Shared\CreateDirectories::class);
            expect($actions)->toContain(Shared\CopyConfigurationFiles::class);
            expect($actions)->toContain(Shared\GenerateCaddyfile::class);
            expect($actions)->toContain(Shared\CreateDockerNetwork::class);
            expect($actions)->toContain(Shared\StartServices::class);
        }
    });

    it('supports Darwin and Linux', function () {
        expect($this->template->supportsPlatform('Darwin'))->toBeTrue();
        expect($this->template->supportsPlatform('Linux'))->toBeTrue();
        expect($this->template->supportsPlatform('Windows'))->toBeFalse();
    });

    it('throws on unsupported platform', function () {
        $this->template->installSteps('Windows');
    })->throws(InvalidArgumentException::class);

    it('returns components for platform', function () {
        $components = $this->template->components('Darwin');

        expect($components)->toHaveCount(4);
        expect($components[0])->toBeInstanceOf(DockerComponent::class);
        expect($components[1])->toBeInstanceOf(PhpComponent::class);
        expect($components[2])->toBeInstanceOf(CaddyComponent::class);
        expect($components[3])->toBeInstanceOf(DnsComponent::class);
    });

    it('returns prepare steps for platform', function () {
        $steps = $this->template->prepareSteps('Darwin');

        expect($steps)->toHaveCount(4);
        expect($steps[0]['name'])->toBe('Checking Docker Runtime');
        expect($steps[1]['name'])->toBe('Checking PHP-FPM');
        expect($steps[2]['name'])->toBe('Checking Caddy Web Server');
        expect($steps[3]['name'])->toBe('Checking DNS Resolver');
    });

    it('returns empty prepare steps for unsupported platform', function () {
        $steps = $this->template->prepareSteps('Windows');

        expect($steps)->toBeEmpty();
    });
});

describe('TemplateRegistry', function () {
    it('returns DevelopmentTemplate by name', function () {
        $registry = new TemplateRegistry;

        expect($registry->get('development'))->toBeInstanceOf(DevelopmentTemplate::class);
    });

    it('checks template existence', function () {
        $registry = new TemplateRegistry;

        expect($registry->has('development'))->toBeTrue();
        expect($registry->has('nonexistent'))->toBeFalse();
    });

    it('throws on unknown template', function () {
        $registry = new TemplateRegistry;

        $registry->get('nonexistent');
    })->throws(InvalidArgumentException::class);

    it('filters templates by platform', function () {
        $registry = new TemplateRegistry;

        $darwin = $registry->forPlatform('Darwin');
        $linux = $registry->forPlatform('Linux');
        $windows = $registry->forPlatform('Windows');

        expect($darwin)->toHaveKey('development');
        expect($linux)->toHaveKey('development');
        expect($windows)->toBeEmpty();
    });

    it('lists all registered templates', function () {
        $registry = new TemplateRegistry;

        expect($registry->all())->toHaveCount(1);
        expect($registry->all())->toHaveKey('development');
    });
});
