<?php

use App\Models\Taxpayer;

it('registers a taxpayer', function (): void {
    $response = $this->postJson('/api/taxpayers', [
        'oib' => '12345678903',
        'name' => 'Salon Ljepota d.o.o.',
        'is_vat_registered' => true,
        'address_line' => 'Ilica 1',
        'city' => 'Zagreb',
        'postcode' => '10000',
        'country_code' => 'HR',
        'iban' => 'HR1210010051863000160',
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'data' => [
                'oib' => '12345678903',
                'name' => 'Salon Ljepota d.o.o.',
                'is_vat_registered' => true,
                'address_line' => 'Ilica 1',
                'city' => 'Zagreb',
                'postcode' => '10000',
                'country_code' => 'HR',
                'iban' => 'HR1210010051863000160',
            ],
        ]);

    $this->assertDatabaseHas('taxpayers', ['oib' => '12345678903']);
});

it('defaults is_vat_registered to false and country_code to HR', function (): void {
    $response = $this->postJson('/api/taxpayers', [
        'oib' => '12345678903',
        'name' => 'Test d.o.o.',
        'address_line' => 'Ilica 1',
        'city' => 'Zagreb',
        'postcode' => '10000',
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'data' => [
                'is_vat_registered' => false,
                'country_code' => 'HR',
                'iban' => null,
            ],
        ]);
});

it('rejects duplicate oib', function (): void {
    Taxpayer::factory()->create(['oib' => '12345678903']);

    $response = $this->postJson('/api/taxpayers', [
        'oib' => '12345678903',
        'name' => 'Another Company',
        'address_line' => 'Ilica 1',
        'city' => 'Zagreb',
        'postcode' => '10000',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('oib');
});

it('rejects invalid oib format', function (): void {
    $response = $this->postJson('/api/taxpayers', [
        'oib' => '123',
        'name' => 'Bad OIB',
        'address_line' => 'Ilica 1',
        'city' => 'Zagreb',
        'postcode' => '10000',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('oib');
});

it('requires name', function (): void {
    $response = $this->postJson('/api/taxpayers', [
        'oib' => '12345678903',
        'address_line' => 'Ilica 1',
        'city' => 'Zagreb',
        'postcode' => '10000',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('requires address_line, city, and postcode', function (): void {
    $response = $this->postJson('/api/taxpayers', [
        'oib' => '12345678903',
        'name' => 'Test d.o.o.',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['address_line', 'city', 'postcode']);
});

it('rejects postcode that is not 5 characters', function (): void {
    $response = $this->postJson('/api/taxpayers', [
        'oib' => '12345678903',
        'name' => 'Test d.o.o.',
        'address_line' => 'Ilica 1',
        'city' => 'Zagreb',
        'postcode' => '123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('postcode');
});

it('shows a taxpayer by oib', function (): void {
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

it('returns 404 for unknown oib', function (): void {
    $response = $this->getJson('/api/taxpayers/00000000000');

    $response->assertNotFound();
});
