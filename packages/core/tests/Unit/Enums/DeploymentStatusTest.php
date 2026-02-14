<?php

declare(strict_types=1);

use HardImpact\Orbit\Core\Enums\DeploymentStatus;

describe('DeploymentStatus', function () {
    it('has all expected cases', function () {
        $cases = array_map(fn ($c) => $c->value, DeploymentStatus::cases());

        expect($cases)->toBe([
            'pending', 'deploying', 'cloning', 'setting_up',
            'installing', 'building', 'active', 'failed', 'removed',
        ]);
    });

    describe('isInProgress', function () {
        it('returns true for in-progress statuses', function (DeploymentStatus $status) {
            expect($status->isInProgress())->toBeTrue();
        })->with([
            DeploymentStatus::Pending,
            DeploymentStatus::Deploying,
            DeploymentStatus::Cloning,
            DeploymentStatus::SettingUp,
            DeploymentStatus::Installing,
            DeploymentStatus::Building,
        ]);

        it('returns false for terminal statuses', function (DeploymentStatus $status) {
            expect($status->isInProgress())->toBeFalse();
        })->with([
            DeploymentStatus::Active,
            DeploymentStatus::Failed,
            DeploymentStatus::Removed,
        ]);
    });

    describe('isTerminal', function () {
        it('returns true for terminal statuses', function (DeploymentStatus $status) {
            expect($status->isTerminal())->toBeTrue();
        })->with([
            DeploymentStatus::Active,
            DeploymentStatus::Failed,
            DeploymentStatus::Removed,
        ]);

        it('returns false for in-progress statuses', function () {
            expect(DeploymentStatus::Deploying->isTerminal())->toBeFalse();
        });
    });
});
