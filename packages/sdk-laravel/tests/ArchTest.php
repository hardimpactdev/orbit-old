<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('data classes extend spatie data')
    ->expect('HardImpact\Orbit\Data')
    ->toExtend('Spatie\LaravelData\Data');

arch('requests extend saloon request')
    ->expect('HardImpact\Orbit\Requests')
    ->toExtend('Saloon\Http\Request');

arch('resources extend saloon base resource')
    ->expect('HardImpact\Orbit\Resources')
    ->toExtend('Saloon\Http\BaseResource');
