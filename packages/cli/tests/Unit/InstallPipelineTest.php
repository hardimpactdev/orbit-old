<?php

use App\Actions\Install\Brew;
use App\Actions\Install\Linux;
use App\Actions\Install\Mac;
use App\Actions\Install\Shared;
use App\Components\CaddyComponent;
use App\Components\DnsComponent;
use App\Components\DockerComponent;
use App\Components\PhpComponent;
use App\Data\Install\InstallContext;
use App\Services\TemplateRegistry;
use App\Templates\PhpDevTemplate;
use App\Templates\PhpProductionTemplate;

describe('PhpDevTemplate', function () {
    beforeEach(function () {
        $this->template = new PhpDevTemplate(
            new DockerComponent,
            new PhpComponent,
            new CaddyComponent,
            new DnsComponent,
        );
    });

    it('returns correct mac step count', function () {
        expect($this->template->installSteps('Darwin'))->toHaveCount(21);
    });

    it('returns correct linux step count', function () {
        expect($this->template->installSteps('Linux'))->toHaveCount(21);
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

    it('includes InstallNodePackageManagers for both platforms', function () {
        foreach (['Darwin', 'Linux'] as $platform) {
            $actions = collect($this->template->installSteps($platform))->pluck('action');

            expect($actions)->toContain(Brew\InstallNodePackageManagers::class);
        }
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

describe('PhpProductionTemplate', function () {
    beforeEach(function () {
        $this->template = new PhpProductionTemplate(
            new DockerComponent,
            new PhpComponent,
            new CaddyComponent,
        );
    });

    it('returns base mac steps without services', function () {
        $steps = $this->template->installSteps('Darwin');

        expect($steps)->toHaveCount(13);
        expect($steps[0]['action'])->toBe(Mac\CheckPrerequisites::class);
        expect($steps[count($steps) - 1]['action'])->toBe(Shared\HealthCheck::class);
    });

    it('returns base linux steps without services', function () {
        $steps = $this->template->installSteps('Linux');

        expect($steps)->toHaveCount(15);
        expect($steps[0]['action'])->toBe(Linux\CheckPrerequisites::class);
        expect($steps[count($steps) - 1]['action'])->toBe(Shared\HealthCheck::class);
    });

    it('includes InstallNodePackageManagers for both platforms', function () {
        $macActions = collect($this->template->installSteps('Darwin'))->pluck('action');
        $linuxActions = collect($this->template->installSteps('Linux'))->pluck('action');

        expect($macActions)->toContain(Brew\InstallNodePackageManagers::class);
        expect($linuxActions)->toContain(Linux\InstallNodePackageManagers::class);
    });

    it('includes Docker steps when services are selected', function () {
        $context = new InstallContext(services: ['postgres', 'redis']);

        $macSteps = $this->template->installSteps('Darwin', $context);
        $linuxSteps = $this->template->installSteps('Linux', $context);

        expect($macSteps)->toHaveCount(18);
        expect($linuxSteps)->toHaveCount(20);

        $macActions = collect($macSteps)->pluck('action');
        $linuxActions = collect($linuxSteps)->pluck('action');

        expect($macActions)->toContain(Mac\InstallOrbStack::class);
        expect($macActions)->toContain(Shared\CreateDockerNetwork::class);
        expect($macActions)->toContain(Shared\StartServices::class);

        expect($linuxActions)->toContain(Linux\InstallDocker::class);
        expect($linuxActions)->toContain(Shared\CreateDockerNetwork::class);
        expect($linuxActions)->toContain(Shared\StartServices::class);
    });

    it('excludes DNS steps regardless of context', function () {
        $context = new InstallContext(services: ['postgres']);

        foreach (['Darwin', 'Linux'] as $platform) {
            $actions = collect($this->template->installSteps($platform, $context))->pluck('action');

            expect($actions)->not->toContain(Shared\GenerateDnsConfig::class);
            expect($actions)->not->toContain(Shared\BuildDockerImages::class);
            expect($actions)->not->toContain(Shared\ConfigureHostsFile::class);
        }
    });

    it('returns only PHP and Caddy components without services', function () {
        $components = $this->template->components('Darwin');

        expect($components)->toHaveCount(2);
        expect($components[0])->toBeInstanceOf(PhpComponent::class);
        expect($components[1])->toBeInstanceOf(CaddyComponent::class);
    });

    it('includes Docker component when services are selected', function () {
        $context = new InstallContext(services: ['postgres']);
        $components = $this->template->components('Darwin', $context);

        expect($components)->toHaveCount(3);
        expect($components[2])->toBeInstanceOf(DockerComponent::class);
    });

    it('supports Darwin and Linux', function () {
        expect($this->template->supportsPlatform('Darwin'))->toBeTrue();
        expect($this->template->supportsPlatform('Linux'))->toBeTrue();
        expect($this->template->supportsPlatform('Windows'))->toBeFalse();
    });

    it('throws on unsupported platform', function () {
        $this->template->installSteps('Windows');
    })->throws(InvalidArgumentException::class);
});

describe('InstallContext', function () {
    it('parses services from options', function () {
        $context = InstallContext::fromOptions(['services' => 'postgres,redis,mailpit']);

        expect($context->services)->toBe(['postgres', 'redis', 'mailpit']);
        expect($context->needsDocker())->toBeTrue();
    });

    it('returns empty services when not provided', function () {
        $context = InstallContext::fromOptions([]);

        expect($context->services)->toBe([]);
        expect($context->needsDocker())->toBeFalse();
    });

    it('parses node package managers from options', function () {
        $context = InstallContext::fromOptions(['node-packages' => 'bun,npm,yarn']);

        expect($context->nodePackageManagers)->toBe(['bun', 'npm', 'yarn']);
    });

    it('returns empty node package managers when not provided', function () {
        $context = InstallContext::fromOptions([]);

        expect($context->nodePackageManagers)->toBe([]);
    });

    it('detects when Node is needed', function () {
        expect((new InstallContext(nodePackageManagers: ['npm']))->needsNode())->toBeTrue();
        expect((new InstallContext(nodePackageManagers: ['yarn']))->needsNode())->toBeTrue();
        expect((new InstallContext(nodePackageManagers: ['pnpm']))->needsNode())->toBeTrue();
        expect((new InstallContext(nodePackageManagers: ['bun']))->needsNode())->toBeFalse();
        expect((new InstallContext(nodePackageManagers: []))->needsNode())->toBeFalse();
    });
});

describe('TemplateRegistry', function () {
    it('returns PhpDevTemplate by name', function () {
        $registry = new TemplateRegistry;

        expect($registry->get('php-dev'))->toBeInstanceOf(PhpDevTemplate::class);
    });

    it('resolves development alias to php-dev', function () {
        $registry = new TemplateRegistry;

        expect($registry->get('development'))->toBeInstanceOf(PhpDevTemplate::class);
    });

    it('resolves php alias to php-dev', function () {
        $registry = new TemplateRegistry;

        expect($registry->get('php'))->toBeInstanceOf(PhpDevTemplate::class);
    });

    it('checks template existence', function () {
        $registry = new TemplateRegistry;

        expect($registry->has('php-dev'))->toBeTrue();
        expect($registry->has('php'))->toBeTrue();
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

        expect($darwin)->toHaveKey('php-dev');
        expect($darwin)->toHaveKey('php-production');
        expect($linux)->toHaveKey('php-dev');
        expect($linux)->toHaveKey('php-production');
        expect($windows)->toBeEmpty();
    });

    it('lists all registered templates', function () {
        $registry = new TemplateRegistry;

        expect($registry->all())->toHaveCount(4);
        expect($registry->all())->toHaveKey('php-dev');
        expect($registry->all())->toHaveKey('php-production');
        expect($registry->all())->toHaveKey('gateway');
        expect($registry->all())->toHaveKey('client');
    });
});
