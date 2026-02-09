<?php

use HardImpact\Orbit\Core\Models\Node;

test('node can be created', function () {
    $node = Node::create([
        'name' => 'Test Server',
        'host' => 'ai',
        'user' => 'orbit',
        'port' => 22,
        'status' => 'active',
    ]);

    $this->assertDatabaseHas('nodes', [
        'name' => 'Test Server',
        'host' => 'ai',
    ]);
});

test('node has status helper methods', function () {
    $node = Node::create([
        'name' => 'Test Server',
        'host' => 'localhost',
        'user' => 'test',
        'port' => 22,
        'status' => 'provisioning',
    ]);

    expect($node->isProvisioning())->toBeTrue()
        ->and($node->isActive())->toBeFalse()
        ->and($node->hasError())->toBeFalse();

    $node->update(['status' => 'active']);
    expect($node->isActive())->toBeTrue();

    $node->update(['status' => 'error']);
    expect($node->hasError())->toBeTrue();
});

test('get ssh connection string for remote node', function () {
    $node = Node::create([
        'name' => 'Remote Server',
        'host' => 'ai',
        'user' => 'orbit',
        'port' => 22,
    ]);

    expect($node->getSshConnectionString())->toBe('orbit@ai');
});

test('get ssh connection string includes port when not 22', function () {
    $node = Node::create([
        'name' => 'Remote Server',
        'host' => 'ai',
        'user' => 'orbit',
        'port' => 2222,
    ]);

    expect($node->getSshConnectionString())->toBe('orbit@ai -p 2222');
});

test('isLocal returns true for localhost', function () {
    $node = Node::create([
        'name' => 'Local',
        'host' => 'localhost',
        'user' => 'test',
        'port' => 22,
    ]);

    expect($node->isLocal())->toBeTrue();
});

test('isLocal returns false for remote host', function () {
    $node = Node::create([
        'name' => 'Remote',
        'host' => 'ai',
        'user' => 'orbit',
        'port' => 22,
    ]);

    expect($node->isLocal())->toBeFalse();
});

test('get ssh connection string returns local for local node', function () {
    $node = Node::create([
        'name' => 'Local',
        'host' => 'localhost',
        'user' => 'test',
        'port' => 22,
    ]);

    expect($node->getSshConnectionString())->toBe('local');
});

test('get default node', function () {
    Node::create([
        'name' => 'Not Default',
        'host' => 'host1',
        'user' => 'test',
        'port' => 22,
        'is_default' => false,
    ]);

    $default = Node::create([
        'name' => 'Default',
        'host' => 'host2',
        'user' => 'test',
        'port' => 22,
        'is_default' => true,
    ]);

    expect(Node::getDefault()->id)->toBe($default->id);
});

test('get default returns null when no default', function () {
    Node::create([
        'name' => 'Not Default',
        'host' => 'host1',
        'user' => 'test',
        'port' => 22,
        'is_default' => false,
    ]);

    expect(Node::getDefault())->toBeNull();
});

test('getSelf returns default node', function () {
    Node::create([
        'name' => 'Remote',
        'host' => 'host1',
        'user' => 'test',
        'port' => 22,
        'is_default' => false,
    ]);

    $self = Node::create([
        'name' => 'Local',
        'host' => 'localhost',
        'user' => 'test',
        'port' => 22,
        'is_default' => true,
    ]);

    expect(Node::getSelf()->id)->toBe($self->id);
});

test('metadata is cast to array', function () {
    $node = Node::create([
        'name' => 'Test Server',
        'host' => 'localhost',
        'user' => 'test',
        'port' => 22,
        'metadata' => ['key' => 'value'],
    ]);

    expect($node->metadata)->toBe(['key' => 'value']);
});

test('provisioning log is cast to array', function () {
    $node = Node::create([
        'name' => 'Test Server',
        'host' => 'localhost',
        'user' => 'test',
        'port' => 22,
        'provisioning_log' => ['step1', 'step2'],
    ]);

    expect($node->provisioning_log)->toBe(['step1', 'step2']);
});
