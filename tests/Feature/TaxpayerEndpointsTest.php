<?php

use App\Models\Taxpayer;

it('registers a taxpayer', function () {
    $response = $this->postJson('/api/taxpayers', [
        'oib' => '12345678903',
        'name' => 'Salon Ljepota d.o.o.',
        'is_vat_registered' => true,
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'data' => [
                'oib' => '12345678903',
                'name' => 'Salon Ljepota d.o.o.',
                'is_vat_registered' => true,
            ],
        ]);

    $this->assertDatabaseHas('taxpayers', ['oib' => '12345678903']);
});

it('defaults is_vat_registered to false', function () {
    $response = $this->postJson('/api/taxpayers', [
        'oib' => '12345678903',
        'name' => 'Test d.o.o.',
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'data' => [
                'is_vat_registered' => false,
            ],
        ]);
});

it('rejects duplicate oib', function () {
    Taxpayer::factory()->create(['oib' => '12345678903']);

    $response = $this->postJson('/api/taxpayers', [
        'oib' => '12345678903',
        'name' => 'Another Company',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('oib');
});

it('rejects invalid oib format', function () {
    $response = $this->postJson('/api/taxpayers', [
        'oib' => '123',
        'name' => 'Bad OIB',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('oib');
});

it('requires name', function () {
    $response = $this->postJson('/api/taxpayers', [
        'oib' => '12345678903',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('shows a taxpayer by oib', function () {
    $taxpayer = Taxpayer::factory()->create(['oib' => '12345678903']);

    $response = $this->getJson('/api/taxpayers/12345678903');

    $response->assertOk()
        ->assertJson([
            'data' => [
                'oib' => '12345678903',
                'name' => $taxpayer->name,
            ],
        ]);
});

it('returns 404 for unknown oib', function () {
    $response = $this->getJson('/api/taxpayers/00000000000');

    $response->assertNotFound();
});
