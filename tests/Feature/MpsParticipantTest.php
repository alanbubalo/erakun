<?php

declare(strict_types=1);

use App\Models\Party;

it('publishes the AS4 endpoint for a served OIB', function (): void {
    config()->set('services.mps.as4_endpoint', 'http://localhost:8000/api/as4/inbox');

    Party::factory()->create(['oib' => '11111111119']);

    $this->getJson('/api/mps/participants/11111111119')
        ->assertStatus(200)
        ->assertExactJson([
            'oib' => '11111111119',
            'as4_endpoint' => 'http://localhost:8000/api/as4/inbox',
        ]);
});

it('returns 404 for an OIB we do not serve', function (): void {
    $this->getJson('/api/mps/participants/99999999999')
        ->assertStatus(404);
});
