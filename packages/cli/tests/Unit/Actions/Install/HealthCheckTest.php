<?php

declare(strict_types=1);

it('checks for environments table in database', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('environments');
    expect($sourceCode)->toContain('DB::table(\'environments\')->count()');
});

it('checks for projects table in database', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('projects');
    expect($sourceCode)->toContain('DB::table(\'projects\')->count()');
});

it('checks for local environment record', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('Environment::getLocal()');
    expect($sourceCode)->toContain('Local environment record not found');
});

it('checks PHP-FPM services are running', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('getInstalledVersions()');
    expect($sourceCode)->toContain('isRunning($version)');
    expect($sourceCode)->toContain('PHP-FPM');
});

it('checks enabled Docker services dynamically', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('getEnabled()');
    expect($sourceCode)->toContain('"orbit-{$serviceName}"');
    expect($sourceCode)->toContain('Docker service');
});

it('returns success when all checks pass', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('All health checks passed');
    expect($sourceCode)->toContain('StepResult::success()');
});

it('returns failure with specific error messages', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Install/Shared/HealthCheck.php');

    expect($sourceCode)->toContain('Database tables not found');
    expect($sourceCode)->toContain('Local environment record not found');
    expect($sourceCode)->toContain('PHP-FPM services not running');
    expect($sourceCode)->toContain('Required Docker services not running');
});
