<?php

declare(strict_types=1);

use App\As4\AmsClient;
use App\As4\AmsMpsPeerEndpointResolver;
use App\As4\ConfigPeerEndpointResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function makeResolver(string $fallbackDefault = ''): AmsMpsPeerEndpointResolver
{
    return new AmsMpsPeerEndpointResolver(
        ams: new AmsClient(baseUrl: 'http://ams.test', timeout: 5),
        fallback: new ConfigPeerEndpointResolver(
            map: ['11111111119' => 'http://fallback-peer.test'],
            defaultPeerUrl: $fallbackDefault,
        ),
        timeout: 5,
    );
}

it('resolves the full AMS → MPS → endpoint chain', function (): void {
    Http::fake([
        'ams.test/ams/participants/11111111119' => Http::response(['mps_url' => 'http://mps.test'], 200),
        'mps.test/api/mps/participants/11111111119' => Http::response([
            'oib' => '11111111119',
            'as4_endpoint' => 'http://mps.test/api/as4/inbox',
        ], 200),
    ]);

    expect(makeResolver()->resolve('11111111119'))->toBe('http://mps.test/api/as4/inbox');
});

it('falls back to the config resolver when the AMS does not know the OIB', function (): void {
    Http::fake([
        'ams.test/ams/participants/*' => Http::response(null, 404),
    ]);

    expect(makeResolver()->resolve('11111111119'))->toBe('http://fallback-peer.test');
});

it('falls back when the AMS is unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('refused'));

    expect(makeResolver()->resolve('11111111119'))->toBe('http://fallback-peer.test');
});

it('falls back when the MPS does not serve the OIB', function (): void {
    Http::fake([
        'ams.test/ams/participants/11111111119' => Http::response(['mps_url' => 'http://mps.test'], 200),
        'mps.test/api/mps/participants/*' => Http::response(null, 404),
    ]);

    expect(makeResolver()->resolve('11111111119'))->toBe('http://fallback-peer.test');
});

it('returns null when discovery misses and no fallback is configured', function (): void {
    Http::fake([
        'ams.test/ams/participants/*' => Http::response(null, 404),
    ]);

    expect(makeResolver()->resolve('99999999999'))->toBeNull();
});

it('memoises a resolution so the same OIB is not looked up twice', function (): void {
    Http::fake([
        'ams.test/ams/participants/11111111119' => Http::response(['mps_url' => 'http://mps.test'], 200),
        'mps.test/api/mps/participants/11111111119' => Http::response([
            'oib' => '11111111119',
            'as4_endpoint' => 'http://mps.test/api/as4/inbox',
        ], 200),
    ]);

    $resolver = makeResolver();
    $resolver->resolve('11111111119');
    $resolver->resolve('11111111119');

    Http::assertSentCount(2); // one AMS + one MPS call, not four
});
