<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.ams.base_url', 'http://ams.test');
    config()->set('services.mps.base_url', 'http://localhost:8000');
});

it('registers an onboarded party in the AMS', function (): void {
    Http::fake(['ams.test/ams/participants/*' => Http::response(null, 204)]);

    $this->postJson('/api/parties', [
        'oib' => '12345678903',
        'name' => 'Salon Ljepota d.o.o.',
        'address_line' => 'Ilica 1',
        'city' => 'Zagreb',
        'postcode' => '10000',
    ])->assertStatus(201);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'http://ams.test/ams/participants/12345678903'
        && $request['mps_url'] === 'http://localhost:8000');
});

it('still onboards when the AMS is unreachable', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('refused'));

    $this->postJson('/api/parties', [
        'oib' => '12345678903',
        'name' => 'Salon Ljepota d.o.o.',
        'address_line' => 'Ilica 1',
        'city' => 'Zagreb',
        'postcode' => '10000',
    ])->assertStatus(201);

    $this->assertDatabaseHas('parties', ['oib' => '12345678903']);
});

it('skips AMS registration when no MPS base URL is configured', function (): void {
    config()->set('services.mps.base_url', '');
    Http::fake();

    $this->postJson('/api/parties', [
        'oib' => '12345678903',
        'name' => 'Salon Ljepota d.o.o.',
        'address_line' => 'Ilica 1',
        'city' => 'Zagreb',
        'postcode' => '10000',
    ])->assertStatus(201);

    Http::assertNothingSent();
});
