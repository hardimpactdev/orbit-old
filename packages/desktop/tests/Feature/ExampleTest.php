<?php

test('homepage redirects to create when no nodes exist', function () {
    if (! config('orbit.multi_node')) {
        $this->get('/')->assertStatus(500); // Middleware fails because no local node

        return;
    }

    $response = $this->get('/');

    $response->assertRedirect('/setup');
});

test('homepage redirects to default node when one exists', function () {
    $node = createNode(['host' => 'localhost']);

    $response = $this->get('/');

    if (config('orbit.multi_node')) {
        $response->assertRedirect("/nodes/{$node->id}");
    } else {
        $response->assertRedirect('/sites');
    }
});
