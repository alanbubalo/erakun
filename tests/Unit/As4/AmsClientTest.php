<?php

declare(strict_types=1);

use App\As4\AmsClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function makeAmsClient(): AmsClient
{
    return new AmsClient(baseUrl: 'http://ams.test', timeout: 5);
}

it('locates the MPS for a registered participant', function (): void {
    Http::fake([
        'ams.test/ams/participants/11111111119' => Http::response(['mps_url' => 'http://mps.test'], 200),
    ]);

    expect(makeAmsClient()->locate('11111111119'))->toBe('http://mps.test');
});

it('returns null when the AMS does not know the participant', function (): void {
    Http::fake(['ams.test/ams/participants/*' => Http::response(null, 404)]);

    expect(makeAmsClient()->locate('99999999999'))->toBeNull();
});

it('returns null when the AMS is unreachable on lookup', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('refused'));

    expect(makeAmsClient()->locate('11111111119'))->toBeNull();
});

it('registers a participant via PUT and reports success', function (): void {
    Http::fake(['ams.test/ams/participants/11111111119' => Http::response(null, 204)]);

    expect(makeAmsClient()->register('11111111119', 'http://localhost:8000'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'http://ams.test/ams/participants/11111111119'
        && $request['mps_url'] === 'http://localhost:8000');
});

it('reports failure when the AMS rejects a registration', function (): void {
    Http::fake(['ams.test/ams/participants/*' => Http::response(null, 422)]);

    expect(makeAmsClient()->register('11111111119', 'http://localhost:8000'))->toBeFalse();
});

it('reports failure when the AMS is unreachable on registration', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('refused'));

    expect(makeAmsClient()->register('11111111119', 'http://localhost:8000'))->toBeFalse();
});
