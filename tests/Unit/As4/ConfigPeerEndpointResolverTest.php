<?php

declare(strict_types=1);

use App\As4\ConfigPeerEndpointResolver;

it('returns the per-OIB override when one is configured', function (): void {
    $resolver = new ConfigPeerEndpointResolver(
        map: ['98765432109' => 'http://peer-a.test', '11122233344' => 'http://peer-b.test'],
        defaultPeerUrl: 'http://default.test',
    );

    expect($resolver->resolve('98765432109'))->toBe('http://peer-a.test')
        ->and($resolver->resolve('11122233344'))->toBe('http://peer-b.test');
});

it('falls back to the default URL when no OIB override is set', function (): void {
    $resolver = new ConfigPeerEndpointResolver(
        map: ['98765432109' => 'http://peer-a.test'],
        defaultPeerUrl: 'http://default.test',
    );

    expect($resolver->resolve('00000000000'))->toBe('http://default.test');
});

it('returns null when no override and no default are configured', function (): void {
    $resolver = new ConfigPeerEndpointResolver(map: [], defaultPeerUrl: '');

    expect($resolver->resolve('00000000000'))->toBeNull();
});
