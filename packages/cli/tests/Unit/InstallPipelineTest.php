<?php

use App\Actions\Install\Linux;
use App\Actions\Install\Mac;
use App\Actions\Install\Shared;
use App\Services\TemplateRegistry;
use App\Templates\DevelopmentTemplate;

describe('DevelopmentTemplate', function () {
    it('returns correct mac step count', function () {
        $template = new DevelopmentTemplate;

        expect($template->installSteps('Darwin'))->toHaveCount(19);
    });

    it('returns correct linux step count', function () {
        $template = new DevelopmentTemplate;

        expect($template->installSteps('Linux'))->toHaveCount(19);
    });

    it('starts mac steps with CheckPrerequisites', function () {
        $template = new DevelopmentTemplate;
        $steps = $template->installSteps('Darwin');

        expect($steps[0]['action'])->toBe(Mac\CheckPrerequisites::class);
    });

    it('starts linux steps with CheckPrerequisites', function () {
        $template = new DevelopmentTemplate;
        $steps = $template->installSteps('Linux');

        expect($steps[0]['action'])->toBe(Linux\CheckPrerequisites::class);
    });

    it('ends mac steps with HealthCheck', function () {
        $template = new DevelopmentTemplate;
        $steps = $template->installSteps('Darwin');

        expect($steps[count($steps) - 1]['action'])->toBe(Shared\HealthCheck::class);
    });

    it('ends linux steps with HealthCheck', function () {
        $template = new DevelopmentTemplate;
        $steps = $template->installSteps('Linux');

        expect($steps[count($steps) - 1]['action'])->toBe(Shared\HealthCheck::class);
    });

    it('includes OrbStack for mac and Docker for linux', function () {
        $template = new DevelopmentTemplate;

        $macActions = collect($template->installSteps('Darwin'))->pluck('action');
        $linuxActions = collect($template->installSteps('Linux'))->pluck('action');

        expect($macActions)->toContain(Mac\InstallOrbStack::class);
        expect($macActions)->not->toContain(Linux\InstallDocker::class);

        expect($linuxActions)->toContain(Linux\InstallDocker::class);
        expect($linuxActions)->not->toContain(Mac\InstallOrbStack::class);
    });

    it('includes all shared actions for both platforms', function () {
        $template = new DevelopmentTemplate;

        foreach (['Darwin', 'Linux'] as $platform) {
            $actions = collect($template->installSteps($platform))->pluck('action');

            expect($actions)->toContain(Shared\CreateDirectories::class);
            expect($actions)->toContain(Shared\CopyConfigurationFiles::class);
            expect($actions)->toContain(Shared\GenerateCaddyfile::class);
            expect($actions)->toContain(Shared\CreateDockerNetwork::class);
            expect($actions)->toContain(Shared\StartServices::class);
        }
    });

    it('supports Darwin and Linux', function () {
        $template = new DevelopmentTemplate;

        expect($template->supportsPlatform('Darwin'))->toBeTrue();
        expect($template->supportsPlatform('Linux'))->toBeTrue();
        expect($template->supportsPlatform('Windows'))->toBeFalse();
    });

    it('throws on unsupported platform', function () {
        $template = new DevelopmentTemplate;

        $template->installSteps('Windows');
    })->throws(InvalidArgumentException::class);
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
