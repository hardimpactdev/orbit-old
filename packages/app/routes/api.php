<?php

use HardImpact\Orbit\App\Http\Controllers\JobController;
use HardImpact\Orbit\App\Http\Controllers\NodeConfigController;
use HardImpact\Orbit\App\Http\Controllers\NodeController;
use HardImpact\Orbit\App\Http\Controllers\NodeProjectController;
use HardImpact\Orbit\App\Http\Controllers\NodeServiceController;
use HardImpact\Orbit\App\Http\Controllers\NodeStatusController;
use HardImpact\Orbit\App\Http\Controllers\PhpConfigController;
use HardImpact\Orbit\App\Http\Controllers\WorkspaceController;
use HardImpact\Orbit\App\Http\Controllers\WorktreeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are stateless (no session) to avoid session locking.
| This allows them to run in parallel without blocking Inertia navigation.
|
| ARCHITECTURE: Dual Route Patterns (Intentional)
|
| This file defines routes in TWO patterns for different clients:
|
| 1. PREFIXED ROUTES: /nodes/{node}/...
|    - Used by DESKTOP app which manages multiple nodes
|    - Node is explicitly specified in URL
|
| 2. FLAT ROUTES: /status, /projects, etc.
|    - Used by WEB app which has single implicit node
|    - Uses implicit.node middleware to inject node
|    - Also provides backward compatibility for desktop's remoteApiUrl calls
|
| This duplication is intentional - do NOT consolidate without understanding
| both client needs. See CLAUDE.md "Direct API Calls" section for details.
|
*/

Route::prefix('nodes/{node}')->group(function (): void {
    // Dashboard data endpoints
    Route::post('test-connection', [NodeController::class, 'testConnection']);
    Route::get('status', [NodeStatusController::class, 'status']);
    Route::get('projects/status', [NodeStatusController::class, 'projects']);
    Route::get('config', [NodeConfigController::class, 'getConfig']);
    Route::get('worktrees', [WorktreeController::class, 'index']);

    // Async data loading endpoints
    Route::get('projects', [NodeProjectController::class, 'indexApi']);
    Route::post('projects/sync', [NodeProjectController::class, 'syncApi']);
    Route::delete('projects/{projectName}', [NodeProjectController::class, 'destroy']);
    Route::get('workspaces', [WorkspaceController::class, 'indexApi']);
    Route::get('workspaces/{workspace}', [WorkspaceController::class, 'showApi']);

    // Service control endpoints (stateless API for Vue async calls)
    Route::post('start', [NodeServiceController::class, 'start']);
    Route::post('stop', [NodeServiceController::class, 'stop']);
    Route::post('restart', [NodeServiceController::class, 'restart']);

    // Individual service routes
    Route::get('services/available', [NodeServiceController::class, 'availableServices']);
    Route::post('services/{service}/start', [NodeServiceController::class, 'startService']);
    Route::post('services/{service}/stop', [NodeServiceController::class, 'stopService']);
    Route::post('services/{service}/restart', [NodeServiceController::class, 'restartService']);
    Route::post('host-services/{service}/start', [NodeServiceController::class, 'startHostService']);
    Route::post('host-services/{service}/stop', [NodeServiceController::class, 'stopHostService']);
    Route::post('host-services/{service}/restart', [NodeServiceController::class, 'restartHostService']);
    Route::get('services/{service}/logs', [NodeServiceController::class, 'serviceLogs']);
    Route::get('host-services/{service}/logs', [NodeServiceController::class, 'hostServiceLogs']);
    Route::post('services/{service}/enable', [NodeServiceController::class, 'enableService']);
    Route::delete('services/{service}', [NodeServiceController::class, 'disableService']);
    Route::put('services/{service}/config', [NodeServiceController::class, 'configureService']);
    Route::get('services/{service}/info', [NodeServiceController::class, 'serviceInfo']);

    // PHP Configuration
    Route::get('php/config/{version?}', [PhpConfigController::class, 'getConfig']);
    Route::post('php/config/{version?}', [PhpConfigController::class, 'setConfig']);
    Route::post('php/{project}', [PhpConfigController::class, 'changePhp']);
    Route::post('php/{project}/reset', [PhpConfigController::class, 'resetPhp']);
});

// Project routes (without node prefix - used when remoteApiUrl is set)
// These are accessed directly via orbit.{tld}/api/projects/{slug}
// Uses implicit.node middleware to inject the local node
Route::middleware('implicit.node')->group(function (): void {
    // Instance info endpoints (for node naming sync)
    Route::get('instance-info', [NodeController::class, 'instanceInfo'])->name('api.instance-info');
    Route::put('instance-info', [NodeController::class, 'updateInstanceInfo'])->name('api.instance-info.update');

    // Flat routes for desktop app compatibility
    Route::get('status', [NodeStatusController::class, 'status'])->name('api.status');
    Route::get('projects', [NodeProjectController::class, 'indexApi'])->name('api.projects');
    Route::get('config', [NodeConfigController::class, 'getConfig'])->name('api.config');
    Route::get('worktrees', [WorktreeController::class, 'index'])->name('api.worktrees');
    Route::get('workspaces', [WorkspaceController::class, 'indexApi'])->name('api.workspaces');
    Route::get('workspaces/{workspace}', [WorkspaceController::class, 'showApi'])->name('api.workspaces.show');

    Route::post('start', [NodeServiceController::class, 'start'])->name('api.start');
    Route::post('stop', [NodeServiceController::class, 'stop'])->name('api.stop');
    Route::post('restart', [NodeServiceController::class, 'restart'])->name('api.restart');

    Route::post('projects', [NodeProjectController::class, 'store'])->name('api.projects.store');
    Route::post('projects/sync', [NodeProjectController::class, 'syncApi'])->name('api.projects.sync');
    Route::delete('projects/{projectName}', [NodeProjectController::class, 'destroy'])->name('api.projects.destroy');
    Route::post('projects/{projectName}/rebuild', [NodeProjectController::class, 'rebuild'])->name('api.projects.rebuild');
    Route::get('projects/{projectSlug}/provision-status', [NodeProjectController::class, 'provisionStatus'])->name('api.projects.provision-status');

    // Service control endpoints (legacy paths)
    Route::get('services/status', [NodeStatusController::class, 'status']);
    Route::post('services/{service}/start', [NodeServiceController::class, 'startService']);
    Route::post('services/{service}/stop', [NodeServiceController::class, 'stopService']);
    Route::post('services/{service}/restart', [NodeServiceController::class, 'restartService']);
    Route::post('services/{service}/enable', [NodeServiceController::class, 'enableService']);
    Route::post('services/{service}/disable', [NodeServiceController::class, 'disableService']);

    // PHP Management
    Route::get('php-versions', function () {
        return response()->json([
            'success' => true,
            'versions' => \HardImpact\Orbit\Core\Support\PhpVersion::SUPPORTED,
        ]);
    });
    Route::post('php/{project}', [PhpConfigController::class, 'changePhp'])->name('api.php.set');
    Route::post('php/{project}/reset', [PhpConfigController::class, 'resetPhp'])->name('api.php.reset');

    // Jobs
    Route::get('jobs/{trackedJob}', [JobController::class, 'show']);
});
