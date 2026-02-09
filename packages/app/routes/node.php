<?php

use HardImpact\Orbit\App\Http\Controllers\DnsController;
use HardImpact\Orbit\App\Http\Controllers\NodeConfigController;
use HardImpact\Orbit\App\Http\Controllers\NodeController;
use HardImpact\Orbit\App\Http\Controllers\NodeProjectController;
use HardImpact\Orbit\App\Http\Controllers\NodeServiceController;
use HardImpact\Orbit\App\Http\Controllers\NodeStatusController;
use HardImpact\Orbit\App\Http\Controllers\PackageController;
use HardImpact\Orbit\App\Http\Controllers\PhpConfigController;
use HardImpact\Orbit\App\Http\Controllers\WorkspaceController;
use HardImpact\Orbit\App\Http\Controllers\WorktreeController;
use Illuminate\Support\Facades\Route;

Route::post('set-default', [NodeController::class, 'setDefault'])->name('nodes.set-default');

// Note: test-connection, status, projects, config, worktrees moved to routes/api.php (stateless)

// Doctor (health checks)
Route::get('doctor', [NodeStatusController::class, 'runDoctor'])->name('nodes.doctor');
Route::get('doctor/quick', [NodeStatusController::class, 'quickCheck'])->name('nodes.doctor.quick');
Route::post('doctor/fix/{check}', [NodeStatusController::class, 'fixIssue'])->name('nodes.doctor.fix');

// Node pages
Route::get('projects', [NodeProjectController::class, 'index'])->name('nodes.projects');
Route::get('services', [NodeController::class, 'servicesPage'])->name('nodes.services');
Route::get('configuration', [NodeController::class, 'settings'])->name('nodes.configuration');
Route::post('configuration', [NodeController::class, 'updateSettings'])->name('nodes.configuration.update');
Route::post('configuration/external-access', [NodeController::class, 'updateExternalAccess'])->name('nodes.configuration.external-access');
// Backwards compatibility: redirect old settings URLs
Route::redirect('settings', 'configuration');
Route::get('settings/{any?}', fn () => redirect()->route('nodes.configuration'))->where('any', '.*');

// Note: api/projects moved to routes/api.php (stateless)
Route::post('start', [NodeServiceController::class, 'start'])->name('nodes.start');
Route::post('stop', [NodeServiceController::class, 'stop'])->name('nodes.stop');
Route::post('restart', [NodeServiceController::class, 'restart'])->name('nodes.restart');
Route::post('php', [PhpConfigController::class, 'changePhp'])->name('nodes.php');
Route::post('php/reset', [PhpConfigController::class, 'resetPhp'])->name('nodes.php.reset');

// Individual service routes
Route::get('services/available', [NodeServiceController::class, 'availableServices'])->name('nodes.services.available');
Route::post('services/{service}/start', [NodeServiceController::class, 'startService'])->name('nodes.services.start');
Route::post('services/{service}/stop', [NodeServiceController::class, 'stopService'])->name('nodes.services.stop');
Route::post('services/{service}/restart', [NodeServiceController::class, 'restartService'])->name('nodes.services.restart');
Route::post('host-services/{service}/start', [NodeServiceController::class, 'startHostService'])->name('nodes.host-services.start');
Route::post('host-services/{service}/stop', [NodeServiceController::class, 'stopHostService'])->name('nodes.host-services.stop');
Route::post('host-services/{service}/restart', [NodeServiceController::class, 'restartHostService'])->name('nodes.host-services.restart');
Route::get('services/{service}/logs', [NodeServiceController::class, 'serviceLogs'])->name('nodes.services.logs');
Route::post('services/{service}/enable', [NodeServiceController::class, 'enableService'])->name('nodes.services.enable');
Route::delete('services/{service}', [NodeServiceController::class, 'disableService'])->name('nodes.services.disable');
Route::put('services/{service}/config', [NodeServiceController::class, 'configureService'])->name('nodes.services.config');
Route::get('services/{service}/info', [NodeServiceController::class, 'serviceInfo'])->name('nodes.services.info');

// Note: GET config and worktrees moved to routes/api.php (stateless)
Route::get('config', [NodeConfigController::class, 'getConfig'])->name('nodes.config');
Route::post('config', [NodeConfigController::class, 'saveConfig'])->name('nodes.config.save');
Route::get('browse-directories', [NodeConfigController::class, 'browseDirectories'])->name('nodes.browse-directories');
Route::get('reverb-config', [NodeConfigController::class, 'getReverbConfig'])->name('nodes.reverb-config');

// Worktree modification routes (need session for CSRF)
Route::post('worktrees/unlink', [WorktreeController::class, 'unlink'])->name('nodes.worktrees.unlink');
Route::post('worktrees/refresh', [WorktreeController::class, 'refresh'])->name('nodes.worktrees.refresh');

// Project routes
Route::get('projects/create', [NodeProjectController::class, 'create'])->name('nodes.projects.create');
Route::post('projects', [NodeProjectController::class, 'store'])->name('nodes.projects.store');
Route::delete('projects/{projectName}', [NodeProjectController::class, 'destroy'])->name('nodes.projects.destroy');
Route::post('projects/{projectName}/rebuild', [NodeProjectController::class, 'rebuild'])->name('nodes.projects.rebuild');
Route::get('projects/{projectSlug}/provision-status', [NodeProjectController::class, 'provisionStatus'])->name('nodes.projects.provision-status');
Route::post('template-defaults', [NodeProjectController::class, 'templateDefaults'])->name('nodes.template-defaults');
Route::get('github-user', [NodeProjectController::class, 'githubUser'])->name('nodes.github-user');
Route::get('github-orgs', [NodeProjectController::class, 'githubOrgs'])->name('nodes.github-orgs');
Route::post('github-repo-exists', [NodeProjectController::class, 'githubRepoExists'])->name('nodes.github-repo-exists');

// DNS mapping routes
Route::get('dns', [DnsController::class, 'index'])->name('nodes.dns.index');
Route::post('dns', [DnsController::class, 'update'])->name('nodes.dns.update');

// Workspace routes (API endpoints moved to routes/api.php for stateless access)
Route::get('workspaces', [WorkspaceController::class, 'index'])->name('nodes.workspaces');
Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('nodes.workspaces.create');
Route::post('workspaces', [WorkspaceController::class, 'store'])->name('nodes.workspaces.store');
Route::get('workspaces/{workspace}', [WorkspaceController::class, 'show'])->name('nodes.workspaces.show');
Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('nodes.workspaces.destroy');
Route::post('workspaces/{workspace}/projects', [WorkspaceController::class, 'addProject'])->name('nodes.workspaces.projects.add');
Route::delete('workspaces/{workspace}/projects/{project}', [WorkspaceController::class, 'removeProject'])->name('nodes.workspaces.projects.remove');

// Package linking routes
Route::get('projects/{project}/linked-packages', [PackageController::class, 'index'])->name('nodes.projects.linked-packages');
Route::post('projects/{project}/link-package', [PackageController::class, 'link'])->name('nodes.projects.link-package');
Route::delete('projects/{project}/unlink-package/{package}', [PackageController::class, 'unlink'])->name('nodes.projects.unlink-package');
