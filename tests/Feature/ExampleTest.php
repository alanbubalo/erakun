<?php

test('the application returns a successful response', function (): void {
    $response = $this->get('/api');

    $response->assertStatus(200);
});
