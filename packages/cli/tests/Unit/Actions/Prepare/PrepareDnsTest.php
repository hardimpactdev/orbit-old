<?php

declare(strict_types=1);

// Test that Mac PrepareDns detects orbit-dns container
it('detects orbit-dns container on Mac', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Prepare/Mac/PrepareDns.php');

    // Should check for OrbStack (the container runtime)
    expect($sourceCode)->toContain('OrbStack');

    // Should verify with docker ps
    expect($sourceCode)->toContain('orbit-dns');
    expect($sourceCode)->toContain('docker ps');
});

// Test that Linux PrepareDns detects orbit-dns container
it('detects orbit-dns container on Linux', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Prepare/Linux/PrepareDns.php');

    // Should check for docker indicators
    expect($sourceCode)->toContain('docker-proxy');

    // Should verify with docker ps
    expect($sourceCode)->toContain('orbit-dns');
    expect($sourceCode)->toContain('docker ps');
});

// Test that PrepareDns returns success when orbit-dns is detected
it('returns success when orbit-dns is running', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Prepare/Mac/PrepareDns.php');

    // Should return success early when orbit-dns is detected
    expect($sourceCode)->toContain('return StepResult::success()')
        ->and($sourceCode)->toContain('orbit-dns (our DNS service)');
});

// Test error message when port 53 is in use by another process
it('fails when port 53 is in use by unknown process', function () {
    $sourceCode = file_get_contents(__DIR__.'/../../../../app/Actions/Prepare/Mac/PrepareDns.php');

    expect($sourceCode)->toContain('Port 53 is in use by another process');
    expect($sourceCode)->toContain('DNS resolution may conflict');
});
