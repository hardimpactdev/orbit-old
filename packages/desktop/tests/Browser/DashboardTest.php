<?php

describe('Dashboard', function () {
    test('redirects to node create when no nodes exist', function () {
        $this->visit('/')
            ->wait(1)
            ->assertPathIs('/nodes/create');
    });
});
