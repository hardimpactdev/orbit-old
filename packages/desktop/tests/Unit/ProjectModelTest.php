<?php

use HardImpact\Orbit\Core\Enums\ProjectStatus;
use HardImpact\Orbit\Core\Models\Project;

test('project can be created', function () {
    Project::create([
        'name' => 'Test Project',
        'slug' => 'test-project',
        'path' => '/home/user/projects/test-project',
        'github_repo' => 'test/project',
    ]);

    $this->assertDatabaseHas('projects', [
        'name' => 'Test Project',
        'slug' => 'test-project',
        'github_repo' => 'test/project',
    ]);
});

test('project can find by slug', function () {
    $project = Project::create([
        'name' => 'Test Project',
        'slug' => 'test-project',
        'path' => '/home/user/projects/test-project',
        'github_repo' => 'test/project',
    ]);

    $found = Project::where('slug', 'test-project')->first();

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($project->id);
});

test('project status helpers work correctly', function () {
    $project = Project::create([
        'name' => 'Test Project',
        'slug' => 'test-project',
        'path' => '/home/user/projects/test-project',
        'status' => ProjectStatus::Queued,
    ]);

    expect($project->isProvisioning())->toBeTrue()
        ->and($project->isReady())->toBeFalse()
        ->and($project->isFailed())->toBeFalse();

    $project->update(['status' => ProjectStatus::Ready]);
    $project->refresh();

    expect($project->isProvisioning())->toBeFalse()
        ->and($project->isReady())->toBeTrue()
        ->and($project->isFailed())->toBeFalse();
});
