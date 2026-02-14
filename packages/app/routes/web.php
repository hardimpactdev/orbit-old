<?php

use HardImpact\Orbit\App\Http\Controllers\DashboardController;
use HardImpact\Orbit\App\Http\Controllers\NodeController;
use HardImpact\Orbit\App\Http\Controllers\ProvisioningController;
use HardImpact\Orbit\App\Http\Controllers\SettingsController;
use HardImpact\Orbit\App\Http\Controllers\SetupController;
use HardImpact\Orbit\App\Http\Controllers\SshKeyController;
use Illuminate\Support\Facades\Route;

// First-Run Setup Routes (accessible without middleware)
Route::prefix('setup')->name('setup.')->group(function (): void {
    Route::get('/', [SetupController::class, 'index'])->name('index');
    Route::get('/check', [SetupController::class, 'check'])->name('check');
    Route::post('/run', [SetupController::class, 'run'])->name('run');
    Route::get('/status', [SetupController::class, 'status'])->name('status');
});

if (config('orbit.multi_node')) {
    // Desktop: Node management + prefixed routes
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::resource('nodes', NodeController::class);

    // Redirect old server/environment routes to nodes
    Route::redirect('/servers', '/nodes')->name('servers.index');
    Route::redirect('/servers/{id}', '/nodes/{id}');
    Route::redirect('/environments', '/nodes');
    Route::redirect('/environments/{id}', '/nodes/{id}');

    Route::post('nodes/{node}/switch', [NodeController::class, 'switchNode'])->name('nodes.switch');

    // SSH Key Management (Desktop-only for now)
    Route::prefix('ssh-keys')->name('ssh-keys.')->group(function (): void {
        Route::post('/', [SshKeyController::class, 'store'])->name('store');
        Route::put('{sshKey}', [SshKeyController::class, 'update'])->name('update');
        Route::delete('{sshKey}', [SshKeyController::class, 'destroy'])->name('destroy');
        Route::post('{sshKey}/default', [SshKeyController::class, 'setDefault'])->name('default');
        Route::get('available', [SshKeyController::class, 'getAvailableKeys'])->name('available');
    });

    // Include node-scoped routes WITH prefix
    Route::prefix('nodes/{node}')
        ->group(__DIR__.'/node.php');
} else {
    // Gate desktop-only management routes with 403 in web mode
    Route::any('/nodes', fn () => abort(403));
    Route::any('/nodes/create', fn () => abort(403));
    Route::any('/environments', fn () => abort(403));
    Route::any('/environments/create', fn () => abort(403));
    Route::any('/ssh-keys/{any?}', fn () => abort(403))->where('any', '.*');

    // Web: Flat routes, middleware injects implicit node
    Route::middleware('implicit.node')->group(function () {
        Route::get('/', [NodeController::class, 'show'])->name('dashboard');

        // Flat routes (e.g. /projects)
        Route::group([], __DIR__.'/node.php');
    });

    // Prefixed routes for compatibility (e.g. /nodes/1/projects)
    Route::prefix('nodes/{node}')
        ->group(__DIR__.'/node.php');

    // Redirect node show to root in web mode
    Route::get('nodes/{node}', fn () => redirect('/'))->name('nodes.show');

    // Gate node edit route specifically
    Route::any('/nodes/{node}/edit', fn () => abort(403));

    // Backwards compatibility: redirect old environment routes
    Route::get('environments/{environment}', fn () => redirect('/'));
}

// SHARED ROUTES (Outside conditional)

// Project routes - forwards to active node's API
Route::post('projects', [\HardImpact\Orbit\App\Http\Controllers\ProjectController::class, 'store'])->name('projects.store');
Route::delete('projects/{slug}', [\HardImpact\Orbit\App\Http\Controllers\ProjectController::class, 'destroy'])->name('projects.destroy');
Route::post('projects/{project}/php', [\HardImpact\Orbit\App\Http\Controllers\ProjectController::class, 'setPhpVersion'])->name('projects.php.set');
Route::post('projects/{project}/php/reset', [\HardImpact\Orbit\App\Http\Controllers\ProjectController::class, 'resetPhpVersion'])->name('projects.php.reset');

// API routes for node data
Route::prefix('api/nodes')->group(function (): void {
    Route::get('tlds', [\HardImpact\Orbit\App\Http\Controllers\NodeConfigController::class, 'getAllTlds'])->name('api.nodes.tlds');
});

// Redirect global settings to node configuration
Route::get('settings', function () {
    $node = \HardImpact\Orbit\Core\Models\Node::getSelf()
        ?? \HardImpact\Orbit\Core\Models\Node::first();

    if ($node) {
        return redirect()->route('nodes.configuration', $node);
    }

    return redirect('/');
})->name('settings.index');

// Redirect /configuration to node configuration
Route::get('configuration', function () {
    $node = \HardImpact\Orbit\Core\Models\Node::getSelf()
        ?? \HardImpact\Orbit\Core\Models\Node::first();

    if ($node) {
        return redirect()->route('nodes.configuration', $node);
    }

    return redirect('/');
})->name('configuration.index');

// Keep POST routes for backwards compatibility (used by desktop app settings)
Route::post('settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('settings/notifications', [SettingsController::class, 'toggleNotifications'])->name('settings.notifications');
Route::post('settings/menu-bar', [SettingsController::class, 'toggleMenuBar'])->name('settings.menu-bar');

// CLI Management Routes
Route::prefix('cli')->name('cli.')->group(function (): void {
    Route::get('status', [SettingsController::class, 'cliStatus'])->name('status');
    Route::post('install', [SettingsController::class, 'cliInstall'])->name('install');
    Route::post('update', [SettingsController::class, 'cliUpdate'])->name('update');
});

// Template Favorites Management
Route::prefix('template-favorites')->name('template-favorites.')->group(function (): void {
    Route::post('/', [SettingsController::class, 'storeTemplate'])->name('store');
    Route::put('{template}', [SettingsController::class, 'updateTemplate'])->name('update');
    Route::delete('{template}', [SettingsController::class, 'destroyTemplate'])->name('destroy');
});

// Provisioning Routes
Route::prefix('provision')->name('provision.')->group(function (): void {
    Route::get('/', [ProvisioningController::class, 'create'])->name('create');
    Route::post('/', [ProvisioningController::class, 'store'])->name('store');
    Route::post('/check-server', [ProvisioningController::class, 'checkServer'])->name('check-server');
    Route::post('/{node}/run', [ProvisioningController::class, 'run'])->name('run');
    Route::get('/{node}/status', [ProvisioningController::class, 'status'])->name('status');
});
